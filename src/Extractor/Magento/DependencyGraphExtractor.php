<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.1B + \u00a76: Typed, directed dependency graph with evidence.
 *
 * Edge types (kept separate, never merged):
 * - module_sequence, composer_require, php_symbol_use,
 * - di_preference, di_virtual_type, plugin_intercept, event_observe
 *
 * Spec \u00a76.2: "Do NOT treat `use Foo\Bar;` alone as a dependency.
 * It's a potential import, not a usage."
 *
 * Coupling metrics are computed on three subsets (\u00a73.1C):
 * - structural: module_sequence + composer_require
 * - code: php_symbol_use
 * - runtime: di_preference + plugin_intercept + event_observe
 */
class DependencyGraphExtractor extends AbstractExtractor
{
    /**
     * Raw edges before module-level lifting.
     * Structure: [ [from_module, to_module, edge_type, evidence[]] ]
     *
     * @var array<array>
     */
    private array $rawEdges = [];

    private int $maxEvidencePerEdge = 5;

    public function getName(): string
    {
        return 'dependencies';
    }

    public function getDescription(): string
    {
        return 'Builds typed dependency graph with evidence and split coupling metrics';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $this->rawEdges = [];
        $this->maxEvidencePerEdge = $this->context
            ? $this->config()->getMaxEvidencePerEdge()
            : 5;

        // 1. Scan PHP files for actual symbol usage (not bare use statements)
        $this->scanPhpSymbolUsage($repoPath, $scopes);

        // 2. Scan di.xml for preferences and virtual types
        $this->scanDiEdges($repoPath, $scopes);

        // 3. Scan di.xml for plugin intercepts
        $this->scanPluginEdges($repoPath, $scopes);

        // 4. Scan events.xml for observer subscriptions
        $this->scanEventEdges($repoPath, $scopes);

        // 5. Lift to module-level edges with exemplar evidence (\u00a76.3)
        $moduleEdges = $this->liftToModuleEdges();

        // 6. Compute coupling metrics on three subsets (\u00a73.1C)
        $allModules = $this->collectAllModules($moduleEdges);
        $couplingMetrics = $this->computeSplitCouplingMetrics($moduleEdges, $allModules);

        // Sort edges by weight descending
        usort($moduleEdges, fn($a, $b) => $b['weight'] <=> $a['weight']);

        return array_merge([
            'edges' => $moduleEdges,
            'coupling_metrics' => $couplingMetrics,
            'summary' => [
                'total_edges' => count($moduleEdges),
                'total_modules_analyzed' => count($allModules),
                'edge_type_counts' => $this->countByEdgeType($moduleEdges),
                'avg_instability' => $couplingMetrics['composite']['avg_instability'] ?? 0,
            ],
        ], $this->integrityMeta());
    }

    /**
     * Scan PHP files for actual symbol usages using AST.
     *
     * Spec \u00a76.2 captures:
     * - "new FQCN", FQCN::staticCall, type hints (params/return/props),
     * - implements/extends, catch(FQCN), DI constructor params
     *
     * Does NOT capture bare `use Foo\Bar;` alone.
     */
    private function scanPhpSymbolUsage(string $repoPath, array $scopes): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());
                if ($ownerModule === 'unknown') {
                    continue;
                }

                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                try {
                    $stmts = $parser->parse($content);
                    if ($stmts === null) {
                        continue;
                    }
                    $stmts = $traverser->traverse($stmts);
                } catch (\Throwable) {
                    $this->warnGeneral("Failed to parse PHP: {$fileId}");
                    continue;
                }

                $this->extractSymbolUsages($stmts, $ownerModule, $fileId);
            }
        }
    }

    /**
     * Walk AST to find actual usages (not imports).
     */
    private function extractSymbolUsages(array $stmts, string $ownerModule, string $fileId): void
    {
        $this->walkNodes($stmts, $ownerModule, $fileId);
    }

    private function walkNodes(array $nodes, string $ownerModule, string $fileId): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // new FQCN()
            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                $this->addPhpEdge($ownerModule, $node->class->toString(), $fileId, $node->getLine(), 'new expression');
            }

            // FQCN::staticCall()
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                $this->addPhpEdge($ownerModule, $node->class->toString(), $fileId, $node->getLine(), 'static call');
            }

            // FQCN::$staticProp or FQCN::CONST
            if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                $this->addPhpEdge($ownerModule, $node->class->toString(), $fileId, $node->getLine(), 'class constant access');
            }

            // extends / implements
            if ($node instanceof Node\Stmt\Class_) {
                if ($node->extends !== null) {
                    $this->addPhpEdge($ownerModule, $node->extends->toString(), $fileId, $node->getLine(), 'extends');
                }
                foreach ($node->implements as $impl) {
                    $this->addPhpEdge($ownerModule, $impl->toString(), $fileId, $node->getLine(), 'implements');
                }
            }

            // interface extends
            if ($node instanceof Node\Stmt\Interface_) {
                foreach ($node->extends as $ext) {
                    $this->addPhpEdge($ownerModule, $ext->toString(), $fileId, $node->getLine(), 'interface extends');
                }
            }

            // use trait
            if ($node instanceof Node\Stmt\TraitUse) {
                foreach ($node->traits as $trait) {
                    $this->addPhpEdge($ownerModule, $trait->toString(), $fileId, $node->getLine(), 'use trait');
                }
            }

            // Type hints: params, return types, property types
            if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                foreach ($node->params as $param) {
                    $this->extractTypeHint($param->type, $ownerModule, $fileId, $node->getLine(), 'param type hint');
                }
                if ($node->returnType !== null) {
                    $this->extractTypeHint($node->returnType, $ownerModule, $fileId, $node->getLine(), 'return type hint');
                }
            }

            if ($node instanceof Node\Stmt\Property && $node->type !== null) {
                $this->extractTypeHint($node->type, $ownerModule, $fileId, $node->getLine(), 'property type hint');
            }

            // catch (FQCN $e)
            if ($node instanceof Node\Stmt\Catch_) {
                foreach ($node->types as $type) {
                    $this->addPhpEdge($ownerModule, $type->toString(), $fileId, $node->getLine(), 'catch');
                }
            }

            // instanceof
            if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
                $this->addPhpEdge($ownerModule, $node->class->toString(), $fileId, $node->getLine(), 'instanceof');
            }

            // Recurse into sub-nodes
            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->$name;
                if (is_array($subNode)) {
                    $this->walkNodes($subNode, $ownerModule, $fileId);
                } elseif ($subNode instanceof Node) {
                    $this->walkNodes([$subNode], $ownerModule, $fileId);
                }
            }
        }
    }

    private function extractTypeHint($type, string $ownerModule, string $fileId, int $line, string $notes): void
    {
        if ($type instanceof Node\Name) {
            $this->addPhpEdge($ownerModule, $type->toString(), $fileId, $line, $notes);
        } elseif ($type instanceof Node\NullableType && $type->type instanceof Node\Name) {
            $this->addPhpEdge($ownerModule, $type->type->toString(), $fileId, $line, $notes);
        } elseif ($type instanceof Node\UnionType) {
            foreach ($type->types as $t) {
                $this->extractTypeHint($t, $ownerModule, $fileId, $line, $notes);
            }
        } elseif ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                $this->extractTypeHint($t, $ownerModule, $fileId, $line, $notes);
            }
        }
    }

    private function addPhpEdge(string $fromModule, string $toClass, string $fileId, int $line, string $notes): void
    {
        // Skip built-in types and self/parent/static
        $normalized = IdentityResolver::normalizeFqcn($toClass);
        if ($this->isBuiltinType($normalized)) {
            return;
        }

        $toModule = $this->resolveModule($normalized);
        if ($toModule === 'unknown' || $toModule === $fromModule) {
            return;
        }

        $this->rawEdges[] = [
            'from' => $fromModule,
            'to' => $toModule,
            'edge_type' => 'php_symbol_use',
            'evidence' => Evidence::fromPhpAst($fileId, $line, null, $notes)->toArray(),
        ];
    }

    /**
     * Scan di.xml for preference and virtualType edges.
     */
    private function scanDiEdges(string $repoPath, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());
                if ($ownerModule === 'unknown') {
                    continue;
                }

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                // Preferences
                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = IdentityResolver::normalizeFqcn((string) ($node['for'] ?? ''));
                    $to = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($for === '') {
                        continue;
                    }
                    $depModule = $this->resolveModule($for);
                    if ($depModule !== 'unknown' && $depModule !== $ownerModule) {
                        $this->rawEdges[] = [
                            'from' => $ownerModule,
                            'to' => $depModule,
                            'edge_type' => 'di_preference',
                            'evidence' => Evidence::fromXml($fileId, "preference for={$for} type={$to}")->toArray(),
                        ];
                    }
                }

                // Virtual types
                foreach ($xml->xpath('//virtualType') ?: [] as $node) {
                    $vType = (string) ($node['name'] ?? '');
                    $baseType = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($baseType === '') {
                        continue;
                    }
                    $depModule = $this->resolveModule($baseType);
                    if ($depModule !== 'unknown' && $depModule !== $ownerModule) {
                        $this->rawEdges[] = [
                            'from' => $ownerModule,
                            'to' => $depModule,
                            'edge_type' => 'di_virtual_type',
                            'evidence' => Evidence::fromXml($fileId, "virtualType name={$vType} type={$baseType}")->toArray(),
                        ];
                    }
                }
            }
        }
    }

    /**
     * Scan di.xml for plugin intercept edges.
     */
    private function scanPluginEdges(string $repoPath, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());
                if ($ownerModule === 'unknown') {
                    continue;
                }

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//type') ?: [] as $typeNode) {
                    $targetClass = IdentityResolver::normalizeFqcn((string) ($typeNode['name'] ?? ''));
                    if ($targetClass === '') {
                        continue;
                    }

                    foreach ($typeNode->plugin ?? [] as $pluginNode) {
                        $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';
                        if ($disabled) {
                            continue;
                        }
                        $pluginClass = (string) ($pluginNode['type'] ?? '');
                        $depModule = $this->resolveModule($targetClass);
                        if ($depModule !== 'unknown' && $depModule !== $ownerModule) {
                            $this->rawEdges[] = [
                                'from' => $ownerModule,
                                'to' => $depModule,
                                'edge_type' => 'plugin_intercept',
                                'evidence' => Evidence::fromXml($fileId, "plugin on {$targetClass} by {$pluginClass}")->toArray(),
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Scan events.xml for event_observe edges.
     */
    private function scanEventEdges(string $repoPath, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('events.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());
                if ($ownerModule === 'unknown') {
                    continue;
                }

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->event ?? [] as $eventNode) {
                    $eventName = (string) ($eventNode['name'] ?? '');
                    if ($eventName === '') {
                        continue;
                    }

                    foreach ($eventNode->observer ?? [] as $observerNode) {
                        $observerClass = IdentityResolver::normalizeFqcn((string) ($observerNode['instance'] ?? ''));
                        if ($observerClass === '') {
                            continue;
                        }
                        $observerModule = $this->resolveModule($observerClass);
                        // event_observe: observer module -> dispatching module (best-effort)
                        // Since we can't determine the dispatcher statically,
                        // we record the observer module subscribing to the event
                        if ($observerModule !== 'unknown' && $observerModule !== $ownerModule) {
                            $this->rawEdges[] = [
                                'from' => $ownerModule,
                                'to' => $observerModule,
                                'edge_type' => 'event_observe',
                                'evidence' => Evidence::fromXml($fileId, "event '{$eventName}' observer {$observerClass}")->toArray(),
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Lift raw class-level edges to module-level edges with exemplar evidence.
     * Spec \u00a76.3: cap evidence to avoid bloat.
     *
     * @return array<array>
     */
    private function liftToModuleEdges(): array
    {
        // Group: "from|to|edge_type" => [evidence1, evidence2, ...]
        $grouped = [];

        foreach ($this->rawEdges as $edge) {
            $key = $edge['from'] . '|' . $edge['to'] . '|' . $edge['edge_type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'from' => $edge['from'],
                    'to' => $edge['to'],
                    'edge_type' => $edge['edge_type'],
                    'evidence' => [],
                    'count' => 0,
                ];
            }
            $grouped[$key]['count']++;
            // Cap evidence to avoid bloat
            if (count($grouped[$key]['evidence']) < $this->maxEvidencePerEdge) {
                $grouped[$key]['evidence'][] = $edge['evidence'];
            }
        }

        $edges = [];
        foreach ($grouped as $data) {
            $edges[] = [
                'from' => ['kind' => 'module', 'id' => $data['from']],
                'to' => ['kind' => 'module', 'id' => $data['to']],
                'edge_type' => $data['edge_type'],
                'weight' => $data['count'],
                'evidence' => $data['evidence'],
            ];
        }

        return $edges;
    }

    /**
     * Collect all unique module IDs from edges.
     */
    private function collectAllModules(array $edges): array
    {
        $modules = [];
        foreach ($edges as $edge) {
            $modules[$edge['from']['id']] = true;
            $modules[$edge['to']['id']] = true;
        }
        $result = array_keys($modules);
        sort($result);
        return $result;
    }

    /**
     * Compute coupling metrics on three subsets per spec \u00a73.1C.
     *
     * structural: module_sequence + composer_require
     * code: php_symbol_use
     * runtime: di_preference + plugin_intercept + event_observe
     */
    private function computeSplitCouplingMetrics(array $edges, array $allModules): array
    {
        $subsets = [
            'structural' => ['module_sequence', 'composer_require'],
            'code' => ['php_symbol_use'],
            'runtime' => ['di_preference', 'di_virtual_type', 'plugin_intercept', 'event_observe'],
        ];

        // Use config if available
        if ($this->context !== null) {
            $configSubsets = $this->config()->getCouplingMetricSubsets();
            if (!empty($configSubsets)) {
                $subsets = $configSubsets;
            }
        }

        $result = [];
        foreach ($subsets as $subsetName => $edgeTypes) {
            $filteredEdges = array_filter($edges, fn($e) => in_array($e['edge_type'], $edgeTypes, true));
            $result[$subsetName] = $this->computeMetricsForEdges($filteredEdges, $allModules);
        }

        // Composite: all edge types
        $result['composite'] = $this->computeMetricsForEdges($edges, $allModules);

        return $result;
    }

    /**
     * Compute Ca, Ce, instability for a given set of edges.
     */
    private function computeMetricsForEdges(array $edges, array $allModules): array
    {
        $efferent = array_fill_keys($allModules, 0);
        $afferent = array_fill_keys($allModules, 0);

        // Count unique module pairs per direction
        $seen = [];
        foreach ($edges as $edge) {
            $from = $edge['from']['id'];
            $to = $edge['to']['id'];
            $key = $from . '->' . $to;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $efferent[$from] = ($efferent[$from] ?? 0) + 1;
                $afferent[$to] = ($afferent[$to] ?? 0) + 1;
            }
        }

        $metrics = [];
        $instabilitySum = 0;
        $counted = 0;

        foreach ($allModules as $mod) {
            $ca = $afferent[$mod] ?? 0;
            $ce = $efferent[$mod] ?? 0;
            $total = $ca + $ce;
            $instability = $total > 0 ? round($ce / $total, 3) : null;

            $metrics[] = [
                'module' => $mod,
                'afferent_coupling' => $ca,
                'efferent_coupling' => $ce,
                'instability' => $instability,
            ];

            if ($instability !== null) {
                $instabilitySum += $instability;
                $counted++;
            }
        }

        usort($metrics, fn($a, $b) => ($b['instability'] ?? 0) <=> ($a['instability'] ?? 0));

        return [
            'modules' => $metrics,
            'avg_instability' => $counted > 0 ? round($instabilitySum / $counted, 3) : 0,
        ];
    }

    private function countByEdgeType(array $edges): array
    {
        $counts = [];
        foreach ($edges as $edge) {
            $type = $edge['edge_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private function isBuiltinType(string $name): bool
    {
        $builtins = [
            'self', 'static', 'parent', 'void', 'never', 'null', 'true', 'false',
            'int', 'float', 'string', 'bool', 'array', 'object', 'callable', 'iterable', 'mixed',
            'Closure', 'Generator', 'Throwable', 'Exception', 'Error',
            'RuntimeException', 'InvalidArgumentException', 'LogicException',
            'stdClass', 'ArrayObject', 'Traversable', 'Iterator', 'IteratorAggregate',
            'Countable', 'Serializable', 'JsonSerializable', 'Stringable',
        ];
        return in_array($name, $builtins, true) || !str_contains($name, '\\');
    }
}

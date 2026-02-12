<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;

/**
 * Architectural debt detection with evidence and 'why this is risky'.
 */
class ArchitecturalDebtExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'architectural_debt';
    }

    public function getDescription(): string
    {
        return 'Detects architectural debt: circular dependencies, god modules, and multiple overrides of same class';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // 1. Build module dependency graph
        $graph = $this->buildDependencyGraph($repoPath, $scopes);

        // 2. Detect circular dependencies
        $cycles = $this->detectCycles($graph);

        // 3. Detect god modules (high centrality)
        $centrality = $this->computeCentrality($graph);
        $godModules = array_filter($centrality, fn($m) => $m['total_connections'] > 10);
        usort($godModules, fn($a, $b) => $b['total_connections'] <=> $a['total_connections']);

        // 4. Detect multiple modules overriding the same class
        $multipleOverrides = $this->findMultipleOverrides($repoPath, $scopes);

        // 5. Compute overall debt indicators
        $debtItems = [];

        foreach ($cycles as $cycle) {
            $pathStr = implode(' → ', $cycle['path']);
            $debtItems[] = [
                'type' => 'circular_dependency',
                'severity' => 'high',
                'description' => 'Circular dependency: ' . $pathStr,
                'why_risky' => 'Circular dependencies prevent independent deployment, testing, and refactoring. Changes cascade unpredictably.',
                'modules' => $cycle['path'],
                'evidence' => [Evidence::fromInference("cycle detected: {$pathStr}")->toArray()],
            ];
        }

        foreach ($godModules as $god) {
            $debtItems[] = [
                'type' => 'god_module',
                'severity' => $god['total_connections'] > 20 ? 'high' : 'medium',
                'description' => "God module: {$god['module']} ({$god['total_connections']} connections)",
                'why_risky' => 'High-centrality modules are single points of failure. Any change ripples across many dependents.',
                'modules' => [$god['module']],
                'evidence' => [Evidence::fromInference("{$god['total_connections']} connections for {$god['module']}")->toArray()],
            ];
        }

        foreach ($multipleOverrides as $override) {
            $count = count($override['modules']);
            $debtItems[] = [
                'type' => 'multiple_override',
                'severity' => $count > 2 ? 'high' : 'medium',
                'description' => "Class {$override['class']} overridden by {$count} modules",
                'why_risky' => 'Multiple modules overriding the same class creates unpredictable load-order conflicts. Only one preference can win.',
                'modules' => $override['modules'],
                'evidence' => [Evidence::fromInference("{$count} modules override {$override['class']}")->toArray()],
            ];
        }

        // Sort by severity
        $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($debtItems, function ($a, $b) use ($severityOrder) {
            return ($severityOrder[$a['severity']] ?? 99) <=> ($severityOrder[$b['severity']] ?? 99);
        });

        return [
            'debt_items' => $debtItems,
            'cycles' => $cycles,
            'god_modules' => $godModules,
            'multiple_overrides' => $multipleOverrides,
            'centrality' => $centrality,
            'summary' => [
                'total_debt_items' => count($debtItems),
                'circular_dependencies' => count($cycles),
                'god_modules' => count($godModules),
                'multiple_overrides' => count($multipleOverrides),
                'by_severity' => $this->countBySeverity($debtItems),
            ],
        ];
    }

    /**
     * Build a directed dependency graph from module.xml sequence dependencies.
     *
     * @return array<string, array<string>> module => [dependencies]
     */
    private function buildDependencyGraph(string $repoPath, array $scopes): array
    {
        $graph = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('module.xml')
                ->path('/^[^\/]+\/[^\/]+\/etc\//')
                ->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                $moduleNode = $xml->module ?? null;
                if ($moduleNode === null) {
                    continue;
                }

                $name = (string) ($moduleNode['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                if (!isset($graph[$name])) {
                    $graph[$name] = [];
                }

                if (isset($moduleNode->sequence)) {
                    foreach ($moduleNode->sequence->module as $dep) {
                        $depName = (string) ($dep['name'] ?? '');
                        if ($depName !== '') {
                            $graph[$name][] = $depName;
                            // Ensure dep exists in graph
                            if (!isset($graph[$depName])) {
                                $graph[$depName] = [];
                            }
                        }
                    }
                }
            }

            // Also add DI-based dependencies (preferences, plugins)
            $diFinder = new Finder();
            $diFinder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($diFinder as $file) {
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                $ownerModule = $this->resolveModuleFromPath($relativePath);
                if ($ownerModule === 'unknown') {
                    continue;
                }

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                // Preferences create dependency on the interface's module
                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = (string) ($node['for'] ?? '');
                    $depModule = $this->resolveModuleFromClass($for);
                    if ($depModule !== 'unknown' && $depModule !== $ownerModule) {
                        if (!isset($graph[$ownerModule])) {
                            $graph[$ownerModule] = [];
                        }
                        if (!in_array($depModule, $graph[$ownerModule], true)) {
                            $graph[$ownerModule][] = $depModule;
                        }
                    }
                }

                // Plugins create dependency on target class's module
                foreach ($xml->xpath('//type') ?: [] as $typeNode) {
                    $targetClass = (string) ($typeNode['name'] ?? '');
                    $depModule = $this->resolveModuleFromClass($targetClass);
                    if ($depModule !== 'unknown' && $depModule !== $ownerModule) {
                        if (!isset($graph[$ownerModule])) {
                            $graph[$ownerModule] = [];
                        }
                        if (!in_array($depModule, $graph[$ownerModule], true)) {
                            $graph[$ownerModule][] = $depModule;
                        }
                    }
                }
            }
        }

        return $graph;
    }

    /**
     * Detect cycles in the dependency graph using DFS.
     *
     * @return array<array{cycle_detected: bool, path: array<string>}>
     */
    private function detectCycles(array $graph): array
    {
        $cycles = [];
        $visited = [];
        $stack = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->dfs($node, $graph, $visited, $stack, $cycles);
            }
        }

        // Deduplicate cycles (same cycle can be found from different start nodes)
        $unique = [];
        foreach ($cycles as $cycle) {
            $key = $this->normalizeCycleKey($cycle['path']);
            if (!isset($unique[$key])) {
                $unique[$key] = $cycle;
            }
        }

        return array_values($unique);
    }

    private function dfs(string $node, array $graph, array &$visited, array &$stack, array &$cycles): void
    {
        $visited[$node] = 'in_progress';
        $stack[] = $node;

        foreach ($graph[$node] ?? [] as $neighbor) {
            if (!isset($visited[$neighbor])) {
                $this->dfs($neighbor, $graph, $visited, $stack, $cycles);
            } elseif ($visited[$neighbor] === 'in_progress') {
                // Found a cycle — extract the cycle path
                $cycleStart = array_search($neighbor, $stack, true);
                if ($cycleStart !== false) {
                    $cyclePath = array_slice($stack, $cycleStart);
                    $cyclePath[] = $neighbor; // Complete the cycle
                    $cycles[] = [
                        'cycle_detected' => true,
                        'path' => $cyclePath,
                        'length' => count($cyclePath) - 1,
                    ];
                }
            }
        }

        array_pop($stack);
        $visited[$node] = 'done';
    }

    /**
     * Normalize a cycle path for deduplication.
     * Rotate so the smallest element is first, then join.
     */
    private function normalizeCycleKey(array $path): string
    {
        // Remove the last element (which duplicates the first)
        $nodes = array_slice($path, 0, -1);
        if (empty($nodes)) {
            return '';
        }

        // Find the minimum element and rotate
        $minIdx = array_search(min($nodes), $nodes, true);
        $rotated = array_merge(array_slice($nodes, $minIdx), array_slice($nodes, 0, $minIdx));

        return implode('->', $rotated);
    }

    /**
     * Compute centrality (in-degree + out-degree) per module.
     *
     * @return array<array>
     */
    private function computeCentrality(array $graph): array
    {
        $outDegree = [];
        $inDegree = [];

        foreach ($graph as $module => $deps) {
            $outDegree[$module] = count($deps);
            if (!isset($inDegree[$module])) {
                $inDegree[$module] = 0;
            }
            foreach ($deps as $dep) {
                $inDegree[$dep] = ($inDegree[$dep] ?? 0) + 1;
            }
        }

        $centrality = [];
        foreach (array_keys($graph) as $module) {
            $in = $inDegree[$module] ?? 0;
            $out = $outDegree[$module] ?? 0;
            $centrality[] = [
                'module' => $module,
                'in_degree' => $in,
                'out_degree' => $out,
                'total_connections' => $in + $out,
            ];
        }

        usort($centrality, fn($a, $b) => $b['total_connections'] <=> $a['total_connections']);

        return $centrality;
    }

    /**
     * Find classes that are overridden by multiple modules via DI preferences.
     *
     * @return array<array>
     */
    private function findMultipleOverrides(string $repoPath, array $scopes): array
    {
        $overrides = []; // class => [modules]

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                $ownerModule = $this->resolveModuleFromPath($relativePath);

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = (string) ($node['for'] ?? '');
                    if ($for === '') {
                        continue;
                    }
                    if (!isset($overrides[$for])) {
                        $overrides[$for] = [];
                    }
                    if (!in_array($ownerModule, $overrides[$for], true)) {
                        $overrides[$for][] = $ownerModule;
                    }
                }
            }
        }

        // Filter to only classes with multiple overrides
        $result = [];
        foreach ($overrides as $class => $modules) {
            if (count($modules) > 1) {
                $result[] = [
                    'class' => $class,
                    'modules' => $modules,
                    'override_count' => count($modules),
                ];
            }
        }

        usort($result, fn($a, $b) => $b['override_count'] <=> $a['override_count']);

        return $result;
    }

    private function resolveModuleFromPath(string $relativePath): string
    {
        if (preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $relativePath, $match)) {
            return $match[1] . '_' . $match[2];
        }
        return 'unknown';
    }

    private function resolveModuleFromClass(string $className): string
    {
        $parts = explode('\\', ltrim($className, '\\'));
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        return 'unknown';
    }

    private function countBySeverity(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $severity = $item['severity'];
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }
        return $counts;
    }
}

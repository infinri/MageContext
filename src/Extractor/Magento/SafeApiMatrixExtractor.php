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
 * Extractor 6 — Safe API Matrix Extractor.
 *
 * Extracts:
 * - For each class used in service contracts: which methods are part of the public API
 *   (exposed via webapi.xml, declared in Api/*Interface) vs internal
 * - Stability classification: @api annotated, interface-declared, or internal-only
 * - Safe-to-call matrix: methods an AI can confidently call vs methods that are
 *   internal implementation details subject to change
 * - Deprecation notices from @deprecated annotations
 *
 * AI failure mode prevented:
 * Using internal (non-@api) methods that may be removed in minor versions,
 * or calling deprecated methods when replacements exist.
 */
class SafeApiMatrixExtractor extends AbstractExtractor
{
    /** Stability tiers from most stable to least */
    private const TIER_API_INTERFACE = 'api_interface';
    private const TIER_API_ANNOTATED = 'api_annotated';
    private const TIER_WEBAPI_EXPOSED = 'webapi_exposed';
    private const TIER_PUBLIC = 'public';
    private const TIER_INTERNAL = 'internal';
    private const TIER_DEPRECATED = 'deprecated';

    /** Stability score per tier (1.0 = safest) */
    private const TIER_SCORES = [
        self::TIER_API_INTERFACE => 1.0,
        self::TIER_API_ANNOTATED => 0.9,
        self::TIER_WEBAPI_EXPOSED => 0.85,
        self::TIER_PUBLIC => 0.5,
        self::TIER_INTERNAL => 0.2,
        self::TIER_DEPRECATED => 0.1,
    ];

    public function getName(): string
    {
        return 'safe_api_matrix';
    }

    public function getDescription(): string
    {
        return 'Classifies methods by API stability tier: interface-declared, @api, webapi-exposed, public, internal, deprecated';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Discover interface-declared methods (Api/*Interface)
        $interfaceMethods = $this->discoverInterfaceMethods($repoPath, $scopes, $parser);

        // 2. Discover webapi-exposed methods
        $webapiMethods = $this->discoverWebapiMethods($repoPath, $scopes);

        // 3. Scan concrete classes for @api, @deprecated, and visibility
        $classMatrix = $this->scanConcreteClasses($repoPath, $scopes, $parser, $interfaceMethods, $webapiMethods);

        // 4. Sort for determinism
        usort($classMatrix, fn($a, $b) => strcmp($a['class'], $b['class']));

        // 5. Build summary
        $allMethods = [];
        foreach ($classMatrix as $cm) {
            foreach ($cm['methods'] as $m) {
                $allMethods[] = $m;
            }
        }

        $tierCounts = [];
        foreach ($allMethods as $m) {
            $tier = $m['stability_tier'];
            $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;
        }

        $deprecatedMethods = array_filter($allMethods, fn($m) => $m['is_deprecated']);
        $deprecatedWithReplacement = array_filter($deprecatedMethods, fn($m) => $m['replacement'] !== null);

        return [
            'class_matrix' => $classMatrix,
            'deprecated_methods' => array_values(array_map(fn($m) => [
                'method_id' => $m['method_id'],
                'class' => $m['class'],
                'method' => $m['method'],
                'replacement' => $m['replacement'],
                'since' => $m['deprecated_since'],
                'evidence' => $m['evidence'],
            ], $deprecatedMethods)),
            'summary' => [
                'total_classes_analyzed' => count($classMatrix),
                'total_methods_classified' => count($allMethods),
                'methods_by_tier' => $tierCounts,
                'total_deprecated' => count($deprecatedMethods),
                'deprecated_with_replacement' => count($deprecatedWithReplacement),
                'by_module' => $this->countByField($classMatrix, 'module'),
            ],
        ];
    }

    /**
     * Discover methods declared in Api/*Interface files.
     *
     * @return array<string, array<string>> class_id => [method_name, ...]
     */
    private function discoverInterfaceMethods(string $repoPath, array $scopes, $parser): array
    {
        $interfaceMethods = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*Interface.php')
                ->path('#Api#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                try {
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new NameResolver());
                    $stmts = $parser->parse($content);
                    if ($stmts === null) {
                        continue;
                    }
                    $stmts = $traverser->traverse($stmts);
                } catch (\Throwable) {
                    continue;
                }

                $interfaceNode = $this->findInterface($stmts);
                if ($interfaceNode === null) {
                    continue;
                }

                $fqcn = $interfaceNode->namespacedName
                    ? $interfaceNode->namespacedName->toString()
                    : '';
                if ($fqcn === '') {
                    continue;
                }

                $classId = IdentityResolver::classId($fqcn);
                $methods = [];
                foreach ($interfaceNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        $methods[] = $stmt->name->toString();
                    }
                }

                if (!empty($methods)) {
                    $interfaceMethods[$classId] = $methods;
                    // Also store by FQCN for matching
                    $interfaceMethods[IdentityResolver::normalizeFqcn($fqcn)] = $methods;
                }
            }
        }

        return $interfaceMethods;
    }

    /**
     * Discover methods exposed via webapi.xml.
     *
     * @return array<string, array<string>> normalized FQCN => [method_name, ...]
     */
    private function discoverWebapiMethods(string $repoPath, array $scopes): array
    {
        $webapiMethods = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('webapi.xml')->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->route ?? [] as $routeNode) {
                    if (!isset($routeNode->service)) {
                        continue;
                    }
                    $serviceClass = IdentityResolver::normalizeFqcn((string) ($routeNode->service['class'] ?? ''));
                    $serviceMethod = (string) ($routeNode->service['method'] ?? '');

                    if ($serviceClass !== '' && $serviceMethod !== '') {
                        $webapiMethods[$serviceClass][] = $serviceMethod;
                    }
                }
            }
        }

        // Deduplicate
        foreach ($webapiMethods as $class => $methods) {
            $webapiMethods[$class] = array_values(array_unique($methods));
        }

        return $webapiMethods;
    }

    /**
     * Scan concrete classes under Model/, Helper/, etc. to classify their methods.
     */
    private function scanConcreteClasses(
        string $repoPath,
        array $scopes,
        $parser,
        array $interfaceMethods,
        array $webapiMethods
    ): array {
        $classMatrix = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->notName('*Interface.php')
                ->exclude(['Test', 'tests', 'view', 'etc', 'i18n'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $result = $this->analyzeClass(
                    $content,
                    $parser,
                    $file->getRealPath(),
                    $repoPath,
                    $interfaceMethods,
                    $webapiMethods
                );

                if ($result !== null) {
                    $classMatrix[] = $result;
                }
            }
        }

        return $classMatrix;
    }

    /**
     * Analyze a single class for API stability classification.
     */
    private function analyzeClass(
        string $content,
        $parser,
        string $filePath,
        string $repoPath,
        array $interfaceMethods,
        array $webapiMethods
    ): ?array {
        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $stmts = $parser->parse($content);
            if ($stmts === null) {
                return null;
            }
            $stmts = $traverser->traverse($stmts);
        } catch (\Throwable) {
            return null;
        }

        $classNode = $this->findClass($stmts);
        if ($classNode === null) {
            return null;
        }

        $fqcn = $classNode->namespacedName
            ? $classNode->namespacedName->toString()
            : ($classNode->name ? $classNode->name->toString() : '');
        if ($fqcn === '') {
            return null;
        }

        $normalizedFqcn = IdentityResolver::normalizeFqcn($fqcn);
        $fileId = $this->fileId($filePath, $repoPath);
        $module = $this->resolveModuleFromFile($filePath);

        // Check if the class itself has @api annotation
        $classDocblock = $this->extractDocblock($classNode);
        $classIsApi = str_contains($classDocblock, '@api');

        // Get interfaces this class implements
        $implementedInterfaces = [];
        foreach ($classNode->implements as $impl) {
            $implementedInterfaces[] = IdentityResolver::normalizeFqcn($impl->toString());
        }

        // Collect interface methods that this class must implement
        $declaredInInterface = [];
        foreach ($implementedInterfaces as $ifaceFqcn) {
            $ifaceMethods = $interfaceMethods[$ifaceFqcn]
                ?? $interfaceMethods[IdentityResolver::classId($ifaceFqcn)]
                ?? [];
            foreach ($ifaceMethods as $m) {
                $declaredInInterface[$m] = $ifaceFqcn;
            }
        }

        // Webapi methods for this class
        $webapiMethodList = $webapiMethods[$normalizedFqcn] ?? [];
        // Also check interface-level webapi
        foreach ($implementedInterfaces as $ifaceFqcn) {
            foreach ($webapiMethods[$ifaceFqcn] ?? [] as $m) {
                $webapiMethodList[] = $m;
            }
        }
        $webapiMethodList = array_unique($webapiMethodList);

        // Analyze each method
        $methods = [];
        $hasAnyApiMethod = false;

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $methodName = $stmt->name->toString();
            $visibility = $this->getVisibility($stmt);
            $docblock = $this->extractDocblock($stmt);

            // Determine stability tier
            $tier = $this->classifyMethodTier(
                $methodName,
                $visibility,
                $docblock,
                $classIsApi,
                $declaredInInterface,
                $webapiMethodList
            );

            // Check deprecation
            $isDeprecated = str_contains($docblock, '@deprecated');
            $replacement = null;
            $deprecatedSince = null;
            if ($isDeprecated) {
                $tier = self::TIER_DEPRECATED;
                if (preg_match('/@see\s+(\S+)/', $docblock, $seeMatch)) {
                    $replacement = $seeMatch[1];
                }
                if (preg_match('/@deprecated\s+(?:since\s+)?(\d+\.\d+[\.\d]*)/', $docblock, $sinceMatch)) {
                    $deprecatedSince = $sinceMatch[1];
                }
                // Also try @since as fallback
                if ($deprecatedSince === null && preg_match('/@since\s+(\d+\.\d+[\.\d]*)/', $docblock, $sinceMatch)) {
                    $deprecatedSince = $sinceMatch[1];
                }
            }

            if ($tier === self::TIER_API_INTERFACE || $tier === self::TIER_API_ANNOTATED || $tier === self::TIER_WEBAPI_EXPOSED) {
                $hasAnyApiMethod = true;
            }

            $methods[] = [
                'method_id' => IdentityResolver::methodId($fqcn, $methodName),
                'class' => $normalizedFqcn,
                'method' => $methodName,
                'visibility' => $visibility,
                'stability_tier' => $tier,
                'stability_score' => self::TIER_SCORES[$tier] ?? 0.5,
                'declared_in_interface' => $declaredInInterface[$methodName] ?? null,
                'webapi_exposed' => in_array($methodName, $webapiMethodList, true),
                'is_api_annotated' => str_contains($docblock, '@api'),
                'is_deprecated' => $isDeprecated,
                'replacement' => $replacement,
                'deprecated_since' => $deprecatedSince,
                'line' => $stmt->getLine(),
                'evidence' => [
                    Evidence::fromPhpAst(
                        $fileId,
                        $stmt->getLine(),
                        $stmt->getEndLine(),
                        "stability: {$tier} — {$normalizedFqcn}::{$methodName}"
                    )->toArray(),
                ],
            ];
        }

        // Only return classes that have at least one API-surface method
        // (skip pure internal classes to keep output focused)
        if (!$hasAnyApiMethod && !$classIsApi && empty($declaredInInterface)) {
            return null;
        }

        if (empty($methods)) {
            return null;
        }

        // Sort methods by stability tier for readability
        usort($methods, function ($a, $b) {
            $tierOrder = [
                self::TIER_API_INTERFACE => 0,
                self::TIER_API_ANNOTATED => 1,
                self::TIER_WEBAPI_EXPOSED => 2,
                self::TIER_PUBLIC => 3,
                self::TIER_INTERNAL => 4,
                self::TIER_DEPRECATED => 5,
            ];
            $orderA = $tierOrder[$a['stability_tier']] ?? 99;
            $orderB = $tierOrder[$b['stability_tier']] ?? 99;
            return $orderA <=> $orderB ?: strcmp($a['method'], $b['method']);
        });

        // Compute class-level safety score (weighted average of method tiers)
        $totalScore = array_sum(array_column($methods, 'stability_score'));
        $avgScore = count($methods) > 0 ? round($totalScore / count($methods), 3) : 0.0;

        return [
            'class' => $normalizedFqcn,
            'class_id' => IdentityResolver::classId($fqcn),
            'module' => $module,
            'is_api_class' => $classIsApi,
            'implements' => $implementedInterfaces,
            'methods' => $methods,
            'method_count' => count($methods),
            'api_method_count' => count(array_filter($methods, fn($m) =>
                in_array($m['stability_tier'], [self::TIER_API_INTERFACE, self::TIER_API_ANNOTATED, self::TIER_WEBAPI_EXPOSED], true)
            )),
            'deprecated_method_count' => count(array_filter($methods, fn($m) => $m['is_deprecated'])),
            'safety_score' => $avgScore,
            'source_file' => $fileId,
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    $classNode->getLine(),
                    $classNode->getEndLine(),
                    "API stability matrix for {$normalizedFqcn}"
                )->toArray(),
            ],
        ];
    }

    /**
     * Classify a method into a stability tier.
     */
    private function classifyMethodTier(
        string $methodName,
        string $visibility,
        string $docblock,
        bool $classIsApi,
        array $declaredInInterface,
        array $webapiMethodList
    ): string {
        // Declared in an Api/*Interface → highest stability
        if (isset($declaredInInterface[$methodName])) {
            return self::TIER_API_INTERFACE;
        }

        // Exposed via webapi.xml
        if (in_array($methodName, $webapiMethodList, true)) {
            return self::TIER_WEBAPI_EXPOSED;
        }

        // Has @api annotation on the method itself
        if (str_contains($docblock, '@api')) {
            return self::TIER_API_ANNOTATED;
        }

        // Class-level @api promotes public methods
        if ($classIsApi && $visibility === 'public') {
            return self::TIER_API_ANNOTATED;
        }

        // Non-public methods are internal
        if ($visibility !== 'public') {
            return self::TIER_INTERNAL;
        }

        // Public but not API-declared
        return self::TIER_PUBLIC;
    }

    private function getVisibility(Node\Stmt\ClassMethod $node): string
    {
        if ($node->isPublic()) {
            return 'public';
        }
        if ($node->isProtected()) {
            return 'protected';
        }
        if ($node->isPrivate()) {
            return 'private';
        }
        return 'public';
    }

    private function findInterface(array $stmts): ?Node\Stmt\Interface_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Interface_) {
                return $stmt;
            }
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Interface_) {
                        return $inner;
                    }
                }
            }
        }
        return null;
    }

    private function findClass(array $stmts): ?Node\Stmt\Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_) {
                return $stmt;
            }
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Class_) {
                        return $inner;
                    }
                }
            }
        }
        return null;
    }

    private function extractDocblock(Node $node): string
    {
        $doc = $node->getDocComment();
        return $doc !== null ? $doc->getText() : '';
    }
}

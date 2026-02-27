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
 * Extractor 8 — Implementation Pattern Extractor.
 *
 * Extracts:
 * - For each concrete class that implements an Api/*Interface: its actual method
 *   signatures, constructor dependencies, and how they differ from the interface
 * - Extra public methods not declared in the interface (extension surface)
 * - Constructor injection dependencies (what services it needs)
 * - Design patterns in use: Factory, Proxy, Repository, ResourceModel delegation
 *
 * AI failure mode prevented:
 * Generating code that correctly implements an interface but misses required
 * constructor dependencies, or that doesn't follow the delegation pattern
 * used by other implementations in the same module.
 */
class ImplementationPatternExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'implementation_patterns';
    }

    public function getDescription(): string
    {
        return 'Extracts concrete implementation signatures, constructor dependencies, and design patterns';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Load DI preference bindings (interface → concrete)
        $diBindings = $this->loadDiBindings($repoPath, $scopes);

        // 2. For each binding, analyze the concrete class
        $implementations = [];
        foreach ($diBindings as $interface => $bindings) {
            foreach ($bindings as $binding) {
                $concrete = $binding['concrete'];

                // Resolve class file
                $filePath = $this->context !== null
                    ? $this->moduleResolver()->resolveClassFile($concrete)
                    : null;

                if ($filePath === null || !is_file($filePath)) {
                    continue;
                }

                $content = @file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }

                $fileId = $this->fileId($filePath, $repoPath);
                $module = $this->resolveModuleFromFile($filePath);

                $impl = $this->analyzeImplementation(
                    $content,
                    $parser,
                    $interface,
                    $concrete,
                    $binding['scope'],
                    $fileId,
                    $module
                );

                if ($impl !== null) {
                    $implementations[] = $impl;
                }
            }
        }

        // 3. Also scan for classes implementing Api interfaces directly (not via di.xml)
        $directImplementations = $this->scanDirectImplementations($repoPath, $scopes, $parser, $diBindings);
        foreach ($directImplementations as $impl) {
            $implementations[] = $impl;
        }

        // 4. Deduplicate by concrete class
        $implementations = $this->deduplicateByClass($implementations);

        // 5. Detect design patterns across implementations
        $patternSummary = $this->detectPatternDistribution($implementations);

        // 6. Sort for determinism
        usort($implementations, fn($a, $b) => strcmp($a['concrete_class'], $b['concrete_class']));

        $totalDeps = array_sum(array_map(fn($i) => count($i['constructor_dependencies']), $implementations));
        $withFactory = count(array_filter($implementations, fn($i) => in_array('factory', $i['patterns'], true)));
        $withProxy = count(array_filter($implementations, fn($i) => in_array('proxy', $i['patterns'], true)));

        return [
            'implementations' => $implementations,
            'pattern_distribution' => $patternSummary,
            'summary' => [
                'total_implementations' => count($implementations),
                'total_constructor_dependencies' => $totalDeps,
                'avg_dependencies' => count($implementations) > 0
                    ? round($totalDeps / count($implementations), 1)
                    : 0,
                'with_factory_pattern' => $withFactory,
                'with_proxy_pattern' => $withProxy,
                'with_extra_methods' => count(array_filter($implementations, fn($i) => !empty($i['extra_methods']))),
                'by_module' => $this->countByField($implementations, 'module'),
            ],
        ];
    }

    /**
     * Analyze a concrete implementation class.
     */
    private function analyzeImplementation(
        string $content,
        $parser,
        string $interface,
        string $concrete,
        string $scope,
        string $fileId,
        string $module
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
            $this->warnGeneral("Failed to parse PHP: {$fileId}");
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

        // Extract constructor dependencies
        $constructorDeps = $this->extractConstructorDependencies($classNode);

        // Extract all public methods
        $publicMethods = $this->extractPublicMethods($classNode, $fqcn, $fileId);

        // Determine which methods are interface-declared vs extra
        $interfaceMethods = $this->resolveInterfaceMethods($interface, $parser);
        $implementedMethods = [];
        $extraMethods = [];

        foreach ($publicMethods as $method) {
            if (in_array($method['name'], $interfaceMethods, true)) {
                $implementedMethods[] = $method;
            } elseif ($method['name'] !== '__construct' && !str_starts_with($method['name'], '__')) {
                $extraMethods[] = $method;
            }
        }

        // Detect design patterns
        $patterns = $this->detectPatterns($content, $constructorDeps);

        // Extract parent class
        $parentClass = null;
        if ($classNode->extends !== null) {
            $parentClass = IdentityResolver::normalizeFqcn($classNode->extends->toString());
        }

        // Extract implemented interfaces
        $implementedInterfaces = [];
        foreach ($classNode->implements as $impl) {
            $implementedInterfaces[] = IdentityResolver::normalizeFqcn($impl->toString());
        }

        return [
            'interface' => $interface,
            'concrete_class' => IdentityResolver::normalizeFqcn($fqcn),
            'concrete_class_id' => IdentityResolver::classId($fqcn),
            'scope' => $scope,
            'module' => $module,
            'parent_class' => $parentClass,
            'implements' => $implementedInterfaces,
            'constructor_dependencies' => $constructorDeps,
            'dependency_count' => count($constructorDeps),
            'implemented_methods' => $implementedMethods,
            'extra_methods' => $extraMethods,
            'patterns' => $patterns,
            'source_file' => $fileId,
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    $classNode->getLine(),
                    $classNode->getEndLine(),
                    "implementation {$fqcn} for {$interface}"
                )->toArray(),
            ],
        ];
    }

    /**
     * Extract constructor dependencies (type-hinted parameters).
     *
     * @return array<array{name: string, type: string, is_interface: bool, is_factory: bool, is_proxy: bool}>
     */
    private function extractConstructorDependencies(Node\Stmt\Class_ $classNode): array
    {
        $deps = [];

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod || $stmt->name->toString() !== '__construct') {
                continue;
            }

            foreach ($stmt->params as $param) {
                $type = $this->resolveType($param->type);
                $name = '$' . $param->var->name;

                $isInterface = str_ends_with($type, 'Interface');
                $isFactory = str_ends_with($type, 'Factory');
                $isProxy = str_ends_with($type, '\\Proxy') || str_ends_with($type, 'Proxy');
                $isCollection = str_contains($type, 'Collection');
                $isRepository = str_contains($type, 'Repository');
                $isLogger = str_contains($type, 'Logger') || str_contains($type, 'Psr\\Log');

                $deps[] = [
                    'name' => $name,
                    'type' => $type,
                    'is_interface' => $isInterface,
                    'is_factory' => $isFactory,
                    'is_proxy' => $isProxy,
                    'is_collection' => $isCollection,
                    'is_repository' => $isRepository,
                    'is_logger' => $isLogger,
                    'optional' => $param->default !== null,
                ];
            }

            break;
        }

        return $deps;
    }

    /**
     * Extract all public methods with their signatures.
     */
    private function extractPublicMethods(Node\Stmt\Class_ $classNode, string $fqcn, string $fileId): array
    {
        $methods = [];

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod || !$stmt->isPublic()) {
                continue;
            }

            $methodName = $stmt->name->toString();

            $params = [];
            foreach ($stmt->params as $param) {
                $params[] = [
                    'name' => '$' . $param->var->name,
                    'type' => $this->resolveType($param->type),
                    'nullable' => $param->type instanceof Node\NullableType,
                    'default' => $param->default !== null,
                ];
            }

            $returnType = $this->resolveType($stmt->returnType);

            $methods[] = [
                'name' => $methodName,
                'method_id' => IdentityResolver::methodId($fqcn, $methodName),
                'parameters' => $params,
                'return_type' => $returnType,
                'is_static' => $stmt->isStatic(),
                'line' => $stmt->getLine(),
                'evidence' => [
                    Evidence::fromPhpAst(
                        $fileId,
                        $stmt->getLine(),
                        $stmt->getEndLine(),
                        "{$fqcn}::{$methodName}"
                    )->toArray(),
                ],
            ];
        }

        return $methods;
    }

    /**
     * Resolve interface methods by parsing the interface file if available.
     *
     * @return string[] method names
     */
    private function resolveInterfaceMethods(string $interfaceFqcn, $parser): array
    {
        $filePath = $this->context !== null
            ? $this->moduleResolver()->resolveClassFile($interfaceFqcn)
            : null;

        if ($filePath === null || !is_file($filePath)) {
            return [];
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $stmts = $parser->parse($content);
            if ($stmts === null) {
                return [];
            }
            $stmts = $traverser->traverse($stmts);
        } catch (\Throwable) {
            return [];
        }

        $interfaceNode = $this->findInterface($stmts);
        if ($interfaceNode === null) {
            return [];
        }

        $methods = [];
        foreach ($interfaceNode->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = $stmt->name->toString();
            }
        }

        return $methods;
    }

    /**
     * Detect design patterns from constructor dependencies and code content.
     *
     * @return string[]
     */
    private function detectPatterns(string $content, array $constructorDeps): array
    {
        $patterns = [];

        // Factory pattern: uses Factory dependencies
        if (count(array_filter($constructorDeps, fn($d) => $d['is_factory'])) > 0) {
            $patterns[] = 'factory';
        }

        // Proxy pattern: uses Proxy dependencies
        if (count(array_filter($constructorDeps, fn($d) => $d['is_proxy'])) > 0) {
            $patterns[] = 'proxy';
        }

        // Repository pattern: delegates to repository
        if (count(array_filter($constructorDeps, fn($d) => $d['is_repository'])) > 0) {
            $patterns[] = 'repository_delegation';
        }

        // Resource model delegation
        if (str_contains($content, 'ResourceModel') || str_contains($content, '$this->resource')) {
            $patterns[] = 'resource_model_delegation';
        }

        // Event dispatching
        if (str_contains($content, 'EventManager') || str_contains($content, 'dispatchEvent')
            || str_contains($content, '_eventManager')) {
            $patterns[] = 'event_dispatching';
        }

        // Collection processing
        if (count(array_filter($constructorDeps, fn($d) => $d['is_collection'])) > 0) {
            $patterns[] = 'collection_processing';
        }

        // SearchCriteria usage
        if (str_contains($content, 'SearchCriteriaBuilder') || str_contains($content, 'SearchCriteriaInterface')) {
            $patterns[] = 'search_criteria';
        }

        // Authorization checks
        if (str_contains($content, 'AuthorizationInterface') || str_contains($content, 'isAllowed')) {
            $patterns[] = 'authorization_check';
        }

        // Transaction management
        if (str_contains($content, 'beginTransaction') || str_contains($content, 'TransactionInterface')) {
            $patterns[] = 'transaction_management';
        }

        return array_unique($patterns);
    }

    /**
     * Scan for classes that directly implement Api interfaces without di.xml binding.
     */
    private function scanDirectImplementations(string $repoPath, array $scopes, $parser, array $diBindings): array
    {
        $implementations = [];
        $boundConcretes = [];
        foreach ($diBindings as $bindings) {
            foreach ($bindings as $b) {
                $boundConcretes[$b['concrete']] = true;
            }
        }

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            // Look in Model/ directories for classes implementing Api interfaces
            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->notName('*Interface.php')
                ->path('#Model#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                // Quick check: does this file implement an Api interface?
                if (!preg_match('/implements\s+.*?\\\\Api\\\\/', $content)) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                $normalized = IdentityResolver::normalizeFqcn($className);
                // Skip if already found via di.xml
                if (isset($boundConcretes[$normalized])) {
                    continue;
                }

                // Find which Api interface it implements
                $apiInterface = $this->extractApiInterface($content);
                if ($apiInterface === null) {
                    continue;
                }

                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());

                $impl = $this->analyzeImplementation(
                    $content,
                    $parser,
                    $apiInterface,
                    $normalized,
                    'direct',
                    $fileId,
                    $module
                );

                if ($impl !== null) {
                    $implementations[] = $impl;
                }
            }
        }

        return $implementations;
    }

    /**
     * Deduplicate implementations by concrete class, preferring di.xml-bound entries.
     */
    private function deduplicateByClass(array $implementations): array
    {
        $seen = [];
        $result = [];

        foreach ($implementations as $impl) {
            $key = $impl['concrete_class'];
            if (isset($seen[$key])) {
                // Prefer non-direct (di.xml bound) over direct
                if ($impl['scope'] !== 'direct') {
                    $result[$seen[$key]] = $impl;
                }
                continue;
            }
            $seen[$key] = count($result);
            $result[] = $impl;
        }

        return array_values($result);
    }

    /**
     * Detect pattern distribution across all implementations.
     */
    private function detectPatternDistribution(array $implementations): array
    {
        $distribution = [];

        foreach ($implementations as $impl) {
            foreach ($impl['patterns'] as $pattern) {
                $distribution[$pattern] = ($distribution[$pattern] ?? 0) + 1;
            }
        }

        arsort($distribution);
        return $distribution;
    }

    /**
     * Extract the first Api/*Interface from an implements clause.
     */
    private function extractApiInterface(string $content): ?string
    {
        // Match "implements SomeApiInterface" or "implements Vendor\Module\Api\FooInterface"
        if (preg_match('/implements\s+([^{]+)/', $content, $m)) {
            $interfaces = array_map('trim', explode(',', $m[1]));
            foreach ($interfaces as $iface) {
                if (str_contains($iface, '\\Api\\') && str_ends_with($iface, 'Interface')) {
                    // Resolve to FQCN if needed
                    $iface = ltrim($iface, '\\');
                    if (!str_contains($iface, '\\')) {
                        // Try to find in use statements
                        if (preg_match('/use\s+([\\\\A-Za-z0-9_]+\\\\' . preg_quote($iface, '/') . ')\s*;/', $content, $useMatch)) {
                            return IdentityResolver::normalizeFqcn($useMatch[1]);
                        }
                    }
                    return IdentityResolver::normalizeFqcn($iface);
                }
            }
        }

        return null;
    }

    /**
     * Load DI preference bindings targeting Api interfaces.
     *
     * @return array<string, array<array{concrete: string, scope: string, module: string}>>
     */
    private function loadDiBindings(string $repoPath, array $scopes): array
    {
        $bindings = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $diScope = $this->detectDiScope($file->getRelativePathname());
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $this->warnInvalidXml($fileId, 'di.xml');
                    continue;
                }

                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = IdentityResolver::normalizeFqcn((string) ($node['for'] ?? ''));
                    $type = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($for === '' || $type === '') {
                        continue;
                    }

                    // Only track bindings for Api interfaces
                    if (!str_contains($for, '\\Api\\') || !str_ends_with($for, 'Interface')) {
                        continue;
                    }

                    $bindings[$for][] = [
                        'concrete' => $type,
                        'scope' => $diScope,
                        'module' => $ownerModule,
                    ];
                }
            }
        }

        return $bindings;
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

    private function resolveType($type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof Node\Name) {
            return IdentityResolver::normalizeFqcn($type->toString());
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->resolveType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->resolveType($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->resolveType($t), $type->types));
        }
        return 'mixed';
    }

    private function extractClassName(string $content): ?string
    {
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $content, $nsMatch)) {
            $namespace = $nsMatch[1];
        }
        if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            $class = $classMatch[1];
        }

        return ($namespace !== '' && $class !== '') ? $namespace . '\\' . $class : null;
    }

    private function detectDiScope(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $scopes = ['frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab'];

        foreach ($scopes as $scope) {
            if (str_contains($normalized, '/etc/' . $scope . '/di.xml')) {
                return $scope;
            }
        }

        return 'global';
    }
}

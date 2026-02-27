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
 * Extractor 3 — Repository Pattern Extractor.
 *
 * Extracts:
 * - All repository interfaces, their CRUD method signatures, and which entities they manage
 * - The SearchCriteria pattern required for code-based lookups (not just ID-based)
 * - Which repositories are appropriate for which domain entities
 *
 * AI failure mode prevented:
 * Partial repository usage that silently falls back to deprecated model methods
 * for non-ID lookups. E.g., CouponRepositoryInterface::getById() takes an ID,
 * not a code — without the full lookup pattern, the AI falls back to loadByCode().
 */
class RepositoryPatternExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'repository_patterns';
    }

    public function getDescription(): string
    {
        return 'Extracts repository interfaces, CRUD methods, SearchCriteria patterns, and entity mappings';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Discover repository interfaces (Api/*RepositoryInterface)
        $repositories = $this->discoverRepositories($repoPath, $scopes, $parser);

        // 2. Load DI bindings for repositories
        $diBindings = $this->loadDiBindings($repoPath, $scopes);

        // 3. Correlate repositories with DI bindings
        foreach ($repositories as &$repo) {
            $iface = $repo['interface'];
            if (isset($diBindings[$iface])) {
                $repo['di_bindings'] = $diBindings[$iface];
            }
        }
        unset($repo);

        // 4. Analyze concrete implementations for SearchCriteria usage patterns
        $repositories = $this->analyzeConcreteImplementations($repoPath, $repositories, $parser);

        // 5. Sort for determinism
        usort($repositories, fn($a, $b) => strcmp($a['interface'], $b['interface']));

        // Summary
        $withSearchCriteria = count(array_filter($repositories, fn($r) => $r['supports_search_criteria']));
        $withGetList = count(array_filter($repositories, fn($r) => $r['has_get_list']));
        $boundCount = count(array_filter($repositories, fn($r) => !empty($r['di_bindings'])));

        return [
            'repositories' => $repositories,
            'search_criteria_guide' => $this->buildSearchCriteriaGuide($repositories),
            'summary' => [
                'total_repositories' => count($repositories),
                'with_search_criteria' => $withSearchCriteria,
                'with_get_list' => $withGetList,
                'bound_in_di' => $boundCount,
                'total_crud_methods' => array_sum(array_map(fn($r) => count($r['methods']), $repositories)),
                'by_module' => $this->countByField($repositories, 'module'),
            ],
        ];
    }

    /**
     * Discover all *RepositoryInterface files under Api/ and extract method signatures.
     */
    private function discoverRepositories(string $repoPath, array $scopes, $parser): array
    {
        $repositories = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*RepositoryInterface.php')
                ->path('#Api#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $repo = $this->parseRepositoryInterface($content, $parser, $fileId, $module);
                if ($repo !== null) {
                    $repositories[] = $repo;
                }
            }
        }

        return $repositories;
    }

    /**
     * Parse a repository interface to extract CRUD method signatures and classify them.
     */
    private function parseRepositoryInterface(string $content, $parser, string $fileId, string $module): ?array
    {
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

        $interfaceNode = $this->findInterface($stmts);
        if ($interfaceNode === null) {
            return null;
        }

        $fqcn = $interfaceNode->namespacedName
            ? $interfaceNode->namespacedName->toString()
            : ($interfaceNode->name ? $interfaceNode->name->toString() : '');
        if ($fqcn === '') {
            return null;
        }

        // Infer the managed entity from interface name
        // e.g., CouponRepositoryInterface → Coupon
        $entityName = $this->inferEntityName($fqcn);

        $methods = [];
        $hasGetList = false;
        $hasGetById = false;
        $hasSave = false;
        $hasDelete = false;
        $hasDeleteById = false;
        $supportsSearchCriteria = false;

        foreach ($interfaceNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $methodName = $stmt->name->toString();
            $returnType = $this->resolveType($stmt->returnType);
            $docblock = $this->extractDocblock($stmt);
            $docMeta = $this->parseDocblockAnnotations($docblock);

            // Parameters
            $params = [];
            $hasSearchCriteriaParam = false;
            foreach ($stmt->params as $param) {
                $paramType = $this->resolveType($param->type);
                $paramName = '$' . $param->var->name;

                if (str_contains($paramType, 'SearchCriteriaInterface') || str_contains($paramType, 'SearchCriteria')) {
                    $hasSearchCriteriaParam = true;
                }

                $params[] = [
                    'name' => $paramName,
                    'type' => $paramType,
                    'nullable' => $param->type instanceof Node\NullableType,
                    'default' => $param->default !== null,
                ];
            }

            // Classify method purpose
            $purpose = $this->classifyMethod($methodName, $params, $returnType, $hasSearchCriteriaParam);

            if ($purpose === 'get_list') {
                $hasGetList = true;
            }
            if ($hasSearchCriteriaParam) {
                $supportsSearchCriteria = true;
            }
            if ($purpose === 'get_by_id') {
                $hasGetById = true;
            }
            if ($purpose === 'save') {
                $hasSave = true;
            }
            if ($purpose === 'delete') {
                $hasDelete = true;
            }
            if ($purpose === 'delete_by_id') {
                $hasDeleteById = true;
            }

            // Lookup constraints: what parameters are required for lookups
            $lookupConstraints = [];
            if (in_array($purpose, ['get_by_id', 'get', 'get_list'], true)) {
                foreach ($params as $p) {
                    $lookupConstraints[] = [
                        'param' => $p['name'],
                        'type' => $p['type'],
                        'note' => $this->describeLookupConstraint($p, $purpose),
                    ];
                }
            }

            $methods[] = [
                'method_id' => IdentityResolver::methodId($fqcn, $methodName),
                'name' => $methodName,
                'purpose' => $purpose,
                'parameters' => $params,
                'return_type' => $returnType,
                'uses_search_criteria' => $hasSearchCriteriaParam,
                'lookup_constraints' => $lookupConstraints,
                'docblock' => $docblock,
                'doc_return' => $docMeta['return'],
                'doc_throws' => $docMeta['throws'],
                'line' => $stmt->getLine(),
                'evidence' => [
                    Evidence::fromPhpAst(
                        $fileId,
                        $stmt->getLine(),
                        $stmt->getEndLine(),
                        "repository method {$fqcn}::{$methodName} ({$purpose})"
                    )->toArray(),
                ],
            ];
        }

        if (empty($methods)) {
            return null;
        }

        // CRUD completeness assessment
        $crudMethods = ['get_by_id' => $hasGetById, 'save' => $hasSave, 'delete' => $hasDelete, 'get_list' => $hasGetList];
        $crudComplete = array_filter($crudMethods);

        return [
            'interface' => IdentityResolver::normalizeFqcn($fqcn),
            'class_id' => IdentityResolver::classId($fqcn),
            'module' => $module,
            'entity_name' => $entityName,
            'methods' => $methods,
            'has_get_list' => $hasGetList,
            'has_get_by_id' => $hasGetById,
            'has_save' => $hasSave,
            'has_delete' => $hasDelete,
            'has_delete_by_id' => $hasDeleteById,
            'supports_search_criteria' => $supportsSearchCriteria,
            'crud_completeness' => $crudMethods,
            'crud_score' => round(count($crudComplete) / 4, 2),
            'di_bindings' => [],
            'concrete_patterns' => [],
            'source_file' => $fileId,
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    $interfaceNode->getLine(),
                    $interfaceNode->getEndLine(),
                    "repository interface {$fqcn} (entity: {$entityName})"
                )->toArray(),
            ],
        ];
    }

    /**
     * Analyze concrete implementations for SearchCriteria usage patterns.
     * Looks for FilterBuilder, SearchCriteriaBuilder usage in concrete repo classes.
     */
    private function analyzeConcreteImplementations(string $repoPath, array $repositories, $parser): array
    {
        foreach ($repositories as &$repo) {
            $concretePatterns = [];

            foreach ($repo['di_bindings'] as $binding) {
                $concrete = $binding['concrete'] ?? '';
                if ($concrete === '') {
                    continue;
                }

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
                $patterns = $this->detectConcretePatterns($content, $fileId, $concrete);
                foreach ($patterns as $p) {
                    $concretePatterns[] = $p;
                }
            }

            $repo['concrete_patterns'] = $concretePatterns;
        }
        unset($repo);

        return $repositories;
    }

    /**
     * Detect patterns in a concrete repository implementation.
     */
    private function detectConcretePatterns(string $content, string $fileId, string $concrete): array
    {
        $patterns = [];

        // SearchCriteriaBuilder usage
        if (str_contains($content, 'SearchCriteriaBuilder') || str_contains($content, 'SearchCriteriaInterface')) {
            $patterns[] = [
                'pattern' => 'search_criteria',
                'class' => $concrete,
                'note' => 'Concrete implementation uses SearchCriteriaBuilder for filtered lookups',
                'evidence' => Evidence::fromPhpAst($fileId, 0, null, "SearchCriteria usage in {$concrete}")->toArray(),
            ];
        }

        // FilterBuilder usage
        if (str_contains($content, 'FilterBuilder') || str_contains($content, 'FilterGroupBuilder')) {
            $patterns[] = [
                'pattern' => 'filter_builder',
                'class' => $concrete,
                'note' => 'Uses FilterBuilder for complex query criteria',
                'evidence' => Evidence::fromPhpAst($fileId, 0, null, "FilterBuilder usage in {$concrete}")->toArray(),
            ];
        }

        // CollectionProcessorInterface usage
        if (str_contains($content, 'CollectionProcessorInterface') || str_contains($content, 'CollectionProcessor')) {
            $patterns[] = [
                'pattern' => 'collection_processor',
                'class' => $concrete,
                'note' => 'Uses CollectionProcessor to apply SearchCriteria to collections',
                'evidence' => Evidence::fromPhpAst($fileId, 0, null, "CollectionProcessor in {$concrete}")->toArray(),
            ];
        }

        // Direct model load (anti-pattern indicator)
        if (preg_match('/->load\s*\(/', $content)) {
            $patterns[] = [
                'pattern' => 'direct_model_load',
                'class' => $concrete,
                'note' => 'WARNING: Uses direct model->load() — may bypass repository layer guarantees',
                'evidence' => Evidence::fromPhpAst($fileId, 0, null, "direct load() in {$concrete}", 0.7)->toArray(),
            ];
        }

        // ResourceModel usage
        if (str_contains($content, 'ResourceModel') || preg_match('/\$this->resource/', $content)) {
            $patterns[] = [
                'pattern' => 'resource_model',
                'class' => $concrete,
                'note' => 'Delegates persistence to ResourceModel layer',
                'evidence' => Evidence::fromPhpAst($fileId, 0, null, "ResourceModel usage in {$concrete}")->toArray(),
            ];
        }

        return $patterns;
    }

    /**
     * Build a guide showing how to use SearchCriteria for each repository
     * that supports getList().
     */
    private function buildSearchCriteriaGuide(array $repositories): array
    {
        $guide = [];

        foreach ($repositories as $repo) {
            if (!$repo['has_get_list']) {
                continue;
            }

            // Find the getList method
            $getListMethod = null;
            foreach ($repo['methods'] as $method) {
                if ($method['purpose'] === 'get_list') {
                    $getListMethod = $method;
                    break;
                }
            }

            if ($getListMethod === null) {
                continue;
            }

            // Find get_by_id method to show what lookup key it uses
            $getByIdMethod = null;
            foreach ($repo['methods'] as $method) {
                if ($method['purpose'] === 'get_by_id') {
                    $getByIdMethod = $method;
                    break;
                }
            }

            $entry = [
                'interface' => $repo['interface'],
                'entity' => $repo['entity_name'],
                'get_list_signature' => $getListMethod['name'] . '(' .
                    implode(', ', array_map(fn($p) => $p['type'] . ' ' . $p['name'], $getListMethod['parameters'])) .
                    '): ' . $getListMethod['return_type'],
                'get_list_returns' => $getListMethod['return_type'],
                'note' => 'Use SearchCriteriaBuilder to construct filters, then pass to getList(). '
                    . 'Do NOT use direct model loading for filtered queries.',
            ];

            if ($getByIdMethod !== null) {
                $idParam = $getByIdMethod['parameters'][0] ?? null;
                $entry['get_by_id_param'] = $idParam
                    ? $idParam['type'] . ' ' . $idParam['name']
                    : 'unknown';
                $entry['id_lookup_note'] = $idParam
                    ? "getById() takes {$idParam['type']} — for non-ID lookups (e.g., by code), use SearchCriteria with getList()"
                    : '';
            }

            $guide[] = $entry;
        }

        usort($guide, fn($a, $b) => strcmp($a['interface'], $b['interface']));
        return $guide;
    }

    /**
     * Classify a method's purpose based on naming conventions and signatures.
     */
    private function classifyMethod(string $name, array $params, string $returnType, bool $hasSearchCriteria): string
    {
        $nameLower = strtolower($name);

        if ($hasSearchCriteria || $nameLower === 'getlist' || $nameLower === 'get_list') {
            return 'get_list';
        }

        if ($nameLower === 'save') {
            return 'save';
        }

        if ($nameLower === 'deletebyid' || $nameLower === 'delete_by_id') {
            return 'delete_by_id';
        }

        if ($nameLower === 'delete') {
            return 'delete';
        }

        if ($nameLower === 'getbyid' || $nameLower === 'get_by_id' || $nameLower === 'getById') {
            return 'get_by_id';
        }

        if (str_starts_with($nameLower, 'get') && count($params) === 1) {
            return 'get_by_id';
        }

        if (str_starts_with($nameLower, 'get')) {
            return 'get';
        }

        return 'other';
    }

    /**
     * Describe what a lookup constraint means for the AI.
     */
    private function describeLookupConstraint(array $param, string $purpose): string
    {
        $type = $param['type'];
        $name = $param['name'];

        if (str_contains($type, 'SearchCriteriaInterface')) {
            return "Pass a SearchCriteria object built via SearchCriteriaBuilder";
        }

        if ($purpose === 'get_by_id') {
            if ($type === 'int' || $type === 'string') {
                return "Lookup by {$type} identifier — for non-ID lookups, use getList() with SearchCriteria";
            }
            return "Lookup parameter {$name} of type {$type}";
        }

        return "Parameter {$name} of type {$type}";
    }

    /**
     * Infer entity name from repository interface FQCN.
     * E.g., Magento\SalesRule\Api\CouponRepositoryInterface → Coupon
     */
    private function inferEntityName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $className = end($parts);

        // Remove 'RepositoryInterface' suffix
        $entity = preg_replace('/RepositoryInterface$/', '', $className);
        return $entity !== '' ? $entity : 'Unknown';
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

    private function extractDocblock(Node $node): string
    {
        $doc = $node->getDocComment();
        return $doc !== null ? $doc->getText() : '';
    }

    private function parseDocblockAnnotations(string $docblock): array
    {
        $result = ['return' => '', 'throws' => []];
        if ($docblock === '') {
            return $result;
        }
        if (preg_match('/@return\s+(\S+)/m', $docblock, $m)) {
            $result['return'] = trim($m[1]);
        }
        if (preg_match_all('/@throws\s+(\S+)/m', $docblock, $matches)) {
            $result['throws'] = $matches[1];
        }
        return $result;
    }

    /**
     * Load DI preference bindings from di.xml.
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

                    // Only collect bindings for Repository interfaces
                    if (!str_contains($for, 'RepositoryInterface')) {
                        continue;
                    }

                    $bindings[$for][] = [
                        'concrete' => $type,
                        'scope' => $diScope,
                        'module' => $ownerModule,
                        'evidence' => Evidence::fromXml(
                            $fileId,
                            "di binding {$for} -> {$type} (scope: {$diScope})"
                        )->toArray(),
                    ];
                }
            }
        }

        return $bindings;
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

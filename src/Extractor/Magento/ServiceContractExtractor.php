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
 * Extractor 2 — Service Contract Extractor.
 *
 * Extracts:
 * - All Api/*Interface method signatures: name, parameters, return types, docblocks
 * - Which interfaces are bound in di.xml to concrete implementations
 * - Which interfaces are exposed via webapi.xml
 *
 * AI failure mode prevented:
 * Using CouponFactory and Model::loadByCode() instead of the repository layer.
 */
class ServiceContractExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'service_contracts';
    }

    public function getDescription(): string
    {
        return 'Extracts Api/*Interface method signatures, DI bindings, and webapi.xml exposure';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Discover all Api/*Interface PHP files and extract method signatures
        $contracts = $this->discoverServiceContracts($repoPath, $scopes, $parser);

        // 2. Load DI bindings (interface → concrete) from di.xml
        $diBindings = $this->loadDiBindings($repoPath, $scopes);

        // 3. Load webapi.xml exposure (interface::method → REST route)
        $webapiExposure = $this->loadWebapiExposure($repoPath, $scopes);

        // 4. Correlate: enrich each contract with its DI binding and webapi exposure
        $enriched = $this->correlateContracts($contracts, $diBindings, $webapiExposure);

        // 5. Sort for determinism
        usort($enriched, fn($a, $b) => strcmp($a['interface'], $b['interface']));

        // 6. Summary
        $boundCount = count(array_filter($enriched, fn($c) => !empty($c['di_bindings'])));
        $exposedCount = count(array_filter($enriched, fn($c) => !empty($c['webapi_routes'])));
        $unboundCount = count(array_filter($enriched, fn($c) => empty($c['di_bindings'])));
        $totalMethods = array_sum(array_map(fn($c) => count($c['methods']), $enriched));

        return [
            'contracts' => $enriched,
            'summary' => [
                'total_service_contracts' => count($enriched),
                'total_methods' => $totalMethods,
                'bound_in_di' => $boundCount,
                'exposed_via_webapi' => $exposedCount,
                'unbound_contracts' => $unboundCount,
                'by_module' => $this->countByField($enriched, 'module'),
            ],
        ];
    }

    /**
     * Discover all Api/*Interface files and extract method signatures via AST.
     *
     * @return array<array>
     */
    private function discoverServiceContracts(string $repoPath, array $scopes, $parser): array
    {
        $contracts = [];

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
                ->notPath('#Api/Data#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $parsed = $this->parseInterfaceMethods($content, $parser, $fileId, $module);
                if ($parsed !== null) {
                    $contracts[] = $parsed;
                }
            }
        }

        return $contracts;
    }

    /**
     * Parse an interface file to extract method signatures.
     */
    private function parseInterfaceMethods(string $content, $parser, string $fileId, string $module): ?array
    {
        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $stmts = $parser->parse($content);
            if ($stmts === null) {
                return null;
            }
            $stmts = $traverser->traverse($stmts);
        } catch (\Throwable $e) {
            $this->warnGeneral("Failed to parse PHP: {$fileId}");
            return null;
        }

        // Find the interface node
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

        $classId = IdentityResolver::classId($fqcn);

        // Extract extends
        $extends = [];
        foreach ($interfaceNode->extends as $ext) {
            $extends[] = IdentityResolver::normalizeFqcn($ext->toString());
        }

        // Extract docblock for the interface itself
        $interfaceDocblock = $this->extractDocblock($interfaceNode);

        // Extract methods
        $methods = [];
        foreach ($interfaceNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $methodName = $stmt->name->toString();
            $methodId = IdentityResolver::methodId($fqcn, $methodName);

            // Parameters
            $params = [];
            foreach ($stmt->params as $param) {
                $paramEntry = [
                    'name' => '$' . $param->var->name,
                    'type' => $this->resolveType($param->type),
                    'nullable' => $param->type instanceof Node\NullableType,
                    'default' => $param->default !== null,
                    'variadic' => $param->variadic,
                ];
                $params[] = $paramEntry;
            }

            // Return type
            $returnType = $this->resolveType($stmt->returnType);

            // Docblock
            $docblock = $this->extractDocblock($stmt);

            // Extract @param and @return from docblock
            $docMeta = $this->parseDocblockAnnotations($docblock);

            $methods[] = [
                'method_id' => $methodId,
                'name' => $methodName,
                'parameters' => $params,
                'return_type' => $returnType,
                'docblock' => $docblock,
                'doc_params' => $docMeta['params'],
                'doc_return' => $docMeta['return'],
                'doc_throws' => $docMeta['throws'],
                'line' => $stmt->getLine(),
                'evidence' => [
                    Evidence::fromPhpAst(
                        $fileId,
                        $stmt->getLine(),
                        $stmt->getEndLine(),
                        "service contract method {$fqcn}::{$methodName}"
                    )->toArray(),
                ],
            ];
        }

        if (empty($methods)) {
            return null;
        }

        // Extract constants
        $constants = [];
        foreach ($interfaceNode->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constants[] = $const->name->toString();
                }
            }
        }

        return [
            'interface' => IdentityResolver::normalizeFqcn($fqcn),
            'class_id' => $classId,
            'module' => $module,
            'extends' => $extends,
            'constants' => $constants,
            'methods' => $methods,
            'method_count' => count($methods),
            'docblock' => $interfaceDocblock,
            'source_file' => $fileId,
            'di_bindings' => [],
            'webapi_routes' => [],
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    $interfaceNode->getLine(),
                    $interfaceNode->getEndLine(),
                    "service contract interface {$fqcn}"
                )->toArray(),
            ],
        ];
    }

    /**
     * Find the first interface declaration in AST statements.
     */
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

    /**
     * Resolve a type node to a string representation.
     */
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
            $parts = array_map(fn($t) => $this->resolveType($t), $type->types);
            return implode('|', $parts);
        }

        if ($type instanceof Node\IntersectionType) {
            $parts = array_map(fn($t) => $this->resolveType($t), $type->types);
            return implode('&', $parts);
        }

        return 'mixed';
    }

    /**
     * Extract docblock string from a node.
     */
    private function extractDocblock(Node $node): string
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return '';
        }
        return $doc->getText();
    }

    /**
     * Parse @param, @return, @throws from a docblock.
     *
     * @return array{params: array, return: string, throws: string[]}
     */
    private function parseDocblockAnnotations(string $docblock): array
    {
        $result = ['params' => [], 'return' => '', 'throws' => []];
        if ($docblock === '') {
            return $result;
        }

        // @param type $name description
        if (preg_match_all('/@param\s+(\S+)\s+(\$\w+)(?:\s+(.*))?/m', $docblock, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $result['params'][] = [
                    'type' => $m[1],
                    'name' => $m[2],
                    'description' => trim($m[3] ?? ''),
                ];
            }
        }

        // @return type description
        if (preg_match('/@return\s+(\S+)(?:\s+(.*))?/m', $docblock, $m)) {
            $result['return'] = trim($m[1] . ' ' . ($m[2] ?? ''));
        }

        // @throws type
        if (preg_match_all('/@throws\s+(\S+)/m', $docblock, $matches)) {
            $result['throws'] = $matches[1];
        }

        return $result;
    }

    /**
     * Load all DI preference bindings from di.xml (interface → concrete, per area).
     *
     * @return array<string, array<array{concrete: string, scope: string, module: string, evidence: array}>>
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

                    $bindings[$for][] = [
                        'concrete' => $type,
                        'scope' => $diScope,
                        'module' => $ownerModule,
                        'evidence' => Evidence::fromXml(
                            $fileId,
                            "preference for={$for} type={$type} scope={$diScope}"
                        )->toArray(),
                    ];
                }
            }
        }

        return $bindings;
    }

    /**
     * Load webapi.xml exposure: which interfaces are exposed as REST/SOAP endpoints.
     *
     * @return array<string, array<array{url: string, http_method: string, service_method: string, resources: string[], evidence: array}>>
     */
    private function loadWebapiExposure(string $repoPath, array $scopes): array
    {
        $exposure = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('webapi.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $this->warnInvalidXml($fileId, 'webapi.xml');
                    continue;
                }

                foreach ($xml->route ?? [] as $routeNode) {
                    $url = (string) ($routeNode['url'] ?? '');
                    $httpMethod = strtoupper((string) ($routeNode['method'] ?? ''));

                    $serviceClass = '';
                    $serviceMethod = '';
                    if (isset($routeNode->service)) {
                        $serviceClass = IdentityResolver::normalizeFqcn((string) ($routeNode->service['class'] ?? ''));
                        $serviceMethod = (string) ($routeNode->service['method'] ?? '');
                    }

                    $resources = [];
                    if (isset($routeNode->resources)) {
                        foreach ($routeNode->resources->resource ?? [] as $resNode) {
                            $ref = (string) ($resNode['ref'] ?? '');
                            if ($ref !== '') {
                                $resources[] = $ref;
                            }
                        }
                    }

                    if ($serviceClass !== '' && $url !== '') {
                        $exposure[$serviceClass][] = [
                            'url' => $url,
                            'http_method' => $httpMethod,
                            'service_method' => $serviceMethod,
                            'resources' => $resources,
                            'evidence' => Evidence::fromXml(
                                $fileId,
                                "webapi {$httpMethod} {$url} -> {$serviceClass}::{$serviceMethod}"
                            )->toArray(),
                        ];
                    }
                }
            }
        }

        return $exposure;
    }

    /**
     * Correlate contracts with DI bindings and webapi exposure.
     */
    private function correlateContracts(array $contracts, array $diBindings, array $webapiExposure): array
    {
        foreach ($contracts as &$contract) {
            $iface = $contract['interface'];

            // DI bindings
            if (isset($diBindings[$iface])) {
                $contract['di_bindings'] = $diBindings[$iface];
            }

            // Webapi exposure
            if (isset($webapiExposure[$iface])) {
                $contract['webapi_routes'] = $webapiExposure[$iface];
            }
        }
        unset($contract);

        return $contracts;
    }

    /**
     * Detect DI scope from relative file path.
     */
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

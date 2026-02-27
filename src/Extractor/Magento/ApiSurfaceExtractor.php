<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * API surface with evidence.
 */
class ApiSurfaceExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'api_surface';
    }

    public function getDescription(): string
    {
        return 'Extracts REST (webapi.xml) and GraphQL schema definitions';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $restEndpoints = [];
        $graphqlTypes = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // REST: webapi.xml
            $webapiFinder = new Finder();
            $webapiFinder->files()
                ->in($scopePath)
                ->name('webapi.xml')
                ->sortByName();

            foreach ($webapiFinder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseWebapiXml($file->getRealPath(), $repoPath, $fileId, $declaringModule);
                foreach ($parsed as $endpoint) {
                    $restEndpoints[] = $endpoint;
                }
            }

            // GraphQL: schema.graphqls
            $graphqlFinder = new Finder();
            $graphqlFinder->files()
                ->in($scopePath)
                ->name('schema.graphqls')
                ->sortByName();

            foreach ($graphqlFinder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseGraphqlSchema($file->getRealPath(), $repoPath, $fileId, $declaringModule);
                foreach ($parsed as $type) {
                    $graphqlTypes[] = $type;
                }
            }
        }

        return [
            'rest_endpoints' => $restEndpoints,
            'graphql_types' => $graphqlTypes,
            'summary' => [
                'total_rest_endpoints' => count($restEndpoints),
                'rest_by_method' => $this->countByField($restEndpoints, 'method'),
                'total_graphql_types' => count($graphqlTypes),
                'graphql_by_kind' => $this->countByField($graphqlTypes, 'kind'),
            ],
        ];
    }

    private function parseWebapiXml(string $filePath, string $repoPath, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'webapi.xml');
            return [];
        }

        $endpoints = [];

        // <route url="/V1/products/:sku" method="GET">
        //   <service class="Magento\Catalog\Api\ProductRepositoryInterface" method="get"/>
        //   <resources>
        //     <resource ref="Magento_Catalog::products"/>
        //   </resources>
        // </route>
        foreach ($xml->route ?? [] as $routeNode) {
            $url = (string) ($routeNode['url'] ?? '');
            $httpMethod = strtoupper((string) ($routeNode['method'] ?? ''));

            $serviceClass = '';
            $serviceMethod = '';
            if (isset($routeNode->service)) {
                $serviceClass = (string) ($routeNode->service['class'] ?? '');
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

            if ($url !== '') {
                $endpoints[] = [
                    'url' => $url,
                    'method' => $httpMethod,
                    'service_class' => IdentityResolver::normalizeFqcn($serviceClass),
                    'service_method' => $serviceMethod,
                    'resources' => $resources,
                    'declared_by' => $declaringModule,
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromXml($fileId, "REST {$httpMethod} {$url} -> {$serviceClass}::{$serviceMethod}")->toArray(),
                    ],
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Basic GraphQL schema parser — extracts type/input/interface/enum declarations and their resolver classes.
     */
    private function parseGraphqlSchema(string $filePath, string $repoPath, string $fileId, string $declaringModule): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $types = [];

        // Match type/input/interface/enum declarations
        // e.g.: type Query { ... }
        //       type ProductInfo @resolver(class: "Vendor\\Module\\Resolver") { ... }
        $pattern = '/\b(type|input|interface|enum|union)\s+(\w+)(?:\s+@\w+(?:\([^)]*\))?)*\s*\{/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $kind = $match[1];
                $name = $match[2];

                // Extract resolver class if present
                $resolver = '';
                $resolverPattern = '/' . preg_quote($match[0], '/') . '.*?@resolver\s*\(\s*class\s*:\s*"([^"]+)"\s*\)/s';
                if (preg_match($resolverPattern, $content, $resolverMatch)) {
                    $resolver = $resolverMatch[1];
                }

                // Also check inline @resolver on the type line itself
                if ($resolver === '') {
                    $linePattern = '/\b' . preg_quote($kind, '/') . '\s+' . preg_quote($name, '/') . '.*?@resolver\s*\(\s*class\s*:\s*"([^"]+)"\s*\)/';
                    if (preg_match($linePattern, $content, $inlineMatch)) {
                        $resolver = $inlineMatch[1];
                    }
                }

                $types[] = [
                    'kind' => $kind,
                    'name' => $name,
                    'resolver_class' => IdentityResolver::normalizeFqcn($resolver),
                    'declared_by' => $declaringModule,
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromXml($fileId, "GraphQL {$kind} {$name}")->toArray(),
                    ],
                ];
            }
        }

        return $types;
    }

}

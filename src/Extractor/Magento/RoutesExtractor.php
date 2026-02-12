<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.2D: Route map.
 *
 * Extracts route declarations from routes.xml with canonical route_id,
 * declared_by module, and evidence.
 */
class RoutesExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'route_map';
    }

    public function getDescription(): string
    {
        return 'Extracts route map from routes.xml with evidence and canonical IDs';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $routes = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('routes.xml')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $area = $this->detectArea($file->getRelativePathname());
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseRoutesXml($file->getRealPath(), $repoPath, $area, $fileId, $declaringModule);
                foreach ($parsed as $route) {
                    $routes[] = $route;
                }
            }
        }

        return [
            'routes' => $routes,
            'summary' => [
                'total_routes' => count($routes),
                'by_area' => $this->countByArea($routes),
                'by_router' => $this->countByRouter($routes),
            ],
        ];
    }

    private function parseRoutesXml(string $filePath, string $repoPath, string $area, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'routes.xml');
            return [];
        }

        $routes = [];

        foreach ($xml->router ?? [] as $routerNode) {
            $routerId = (string) ($routerNode['id'] ?? '');

            foreach ($routerNode->route ?? [] as $routeNode) {
                $routeXmlId = (string) ($routeNode['id'] ?? '');
                $frontName = (string) ($routeNode['frontName'] ?? '');

                $modules = [];
                foreach ($routeNode->module ?? [] as $moduleNode) {
                    $moduleName = (string) ($moduleNode['name'] ?? '');
                    $beforeModule = (string) ($moduleNode['before'] ?? '');
                    $afterModule = (string) ($moduleNode['after'] ?? '');

                    if ($moduleName !== '') {
                        $entry = ['name' => $moduleName];
                        if ($beforeModule !== '') {
                            $entry['before'] = $beforeModule;
                        }
                        if ($afterModule !== '') {
                            $entry['after'] = $afterModule;
                        }
                        $modules[] = $entry;
                    }
                }

                if ($routeXmlId !== '') {
                    $canonicalId = IdentityResolver::routeId($area, $frontName ?: $routeXmlId, $routeXmlId);

                    $routes[] = [
                        'route_id' => $canonicalId,
                        'xml_id' => $routeXmlId,
                        'front_name' => $frontName,
                        'router' => $routerId,
                        'area' => $area,
                        'modules' => $modules,
                        'declared_by' => $declaringModule,
                        'evidence' => [
                            Evidence::fromXml(
                                $fileId,
                                "route id={$routeXmlId} frontName={$frontName} router={$routerId}"
                            )->toArray(),
                        ],
                    ];
                }
            }
        }

        return $routes;
    }

    private function detectArea(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (str_contains($normalized, '/adminhtml/')) {
            return 'adminhtml';
        }
        if (str_contains($normalized, '/frontend/')) {
            return 'frontend';
        }
        return 'global';
    }

    private function countByArea(array $routes): array
    {
        $counts = [];
        foreach ($routes as $r) {
            $area = $r['area'];
            $counts[$area] = ($counts[$area] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    private function countByRouter(array $routes): array
    {
        $counts = [];
        foreach ($routes as $r) {
            $router = $r['router'];
            $counts[$router] = ($counts[$router] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

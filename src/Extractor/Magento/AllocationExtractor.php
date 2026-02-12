<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * C.6: Allocation view — per-area module allocation.
 *
 * Shows which modules are active in which Magento areas:
 * - global, frontend, adminhtml, webapi_rest, webapi_soap, graphql, crontab
 *
 * Derived from:
 * - etc/{area}/ directory presence
 * - di.xml area-specific configs
 * - routes.xml area declarations
 * - view/{area}/ directory presence
 *
 * Output: allocation_view/areas.json
 */
class AllocationExtractor extends AbstractExtractor
{
    private const AREAS = ['global', 'frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab'];

    public function getName(): string
    {
        return 'areas';
    }

    public function getDescription(): string
    {
        return 'Maps modules to Magento areas (global, frontend, adminhtml, webapi, crontab)';
    }

    public function getOutputView(): string
    {
        return 'allocation_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $areas = [];
        foreach (self::AREAS as $area) {
            $areas[$area] = [
                'area' => $area,
                'modules' => [],
                'module_count' => 0,
            ];
        }

        // Scan each scope for module area presence
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // Find module directories (Vendor/Module pattern)
            $this->scanModuleAreas($scopePath, $repoPath, $areas);
        }

        // Sort modules within each area and compute counts
        foreach ($areas as $area => &$data) {
            $data['modules'] = array_values(array_unique($data['modules']));
            sort($data['modules']);
            $data['module_count'] = count($data['modules']);
        }
        unset($data);

        // Build cross-reference: module → areas
        $moduleAreas = [];
        foreach ($areas as $area => $data) {
            foreach ($data['modules'] as $moduleId) {
                $moduleAreas[$moduleId][] = $area;
            }
        }
        ksort($moduleAreas);

        // Area overlap summary
        $multiAreaModules = array_filter($moduleAreas, fn($a) => count($a) > 1);

        return [
            'areas' => $areas,
            'module_areas' => $moduleAreas,
            'summary' => [
                'total_areas_active' => count(array_filter($areas, fn($a) => $a['module_count'] > 0)),
                'total_module_area_mappings' => array_sum(array_column($areas, 'module_count')),
                'multi_area_modules' => count($multiAreaModules),
            ],
        ];
    }

    private function scanModuleAreas(string $scopePath, string $repoPath, array &$areas): void
    {
        // Find all module.xml files to discover modules
        $finder = new Finder();
        $finder->files()
            ->in($scopePath)
            ->name('module.xml')
            ->path('/etc/')
            ->depth('< 4')
            ->sortByName();

        foreach ($finder as $file) {
            $moduleDir = dirname(dirname($file->getRealPath())); // up from etc/module.xml
            $moduleId = $this->resolveModuleFromFile($file->getRealPath());

            if ($moduleId === 'unknown') {
                // Try inferring from directory structure
                $relPath = str_replace($repoPath . '/', '', $moduleDir);
                if (preg_match('#(?:app/code)/([^/]+)/([^/]+)$#', $relPath, $m)) {
                    $moduleId = $m[1] . '_' . $m[2];
                } else {
                    continue;
                }
            }

            // etc/ directory is always global
            $areas['global']['modules'][] = $moduleId;

            // Check area-specific etc/ subdirectories
            $etcDir = $moduleDir . '/etc';
            foreach (self::AREAS as $area) {
                if ($area === 'global') {
                    continue;
                }
                if (is_dir($etcDir . '/' . $area)) {
                    $areas[$area]['modules'][] = $moduleId;
                }
            }

            // Check view/ directory for frontend/adminhtml presence
            $viewDir = $moduleDir . '/view';
            if (is_dir($viewDir)) {
                if (is_dir($viewDir . '/frontend')) {
                    $areas['frontend']['modules'][] = $moduleId;
                }
                if (is_dir($viewDir . '/adminhtml')) {
                    $areas['adminhtml']['modules'][] = $moduleId;
                }
                if (is_dir($viewDir . '/base')) {
                    // base applies to both frontend and adminhtml
                    $areas['frontend']['modules'][] = $moduleId;
                    $areas['adminhtml']['modules'][] = $moduleId;
                }
            }

            // Check for Controller directories (implies frontend/adminhtml)
            if (is_dir($moduleDir . '/Controller/Adminhtml')) {
                $areas['adminhtml']['modules'][] = $moduleId;
            }
            $controllerDir = $moduleDir . '/Controller';
            if (is_dir($controllerDir) && !is_dir($controllerDir . '/Adminhtml')) {
                // Has controllers but not admin → frontend
                $areas['frontend']['modules'][] = $moduleId;
            }

            // Check for Console/Command → crontab-adjacent
            if (is_dir($moduleDir . '/Console')) {
                $areas['crontab']['modules'][] = $moduleId;
            }

            // Check for Cron directory
            if (is_dir($moduleDir . '/Cron')) {
                $areas['crontab']['modules'][] = $moduleId;
            }
        }
    }
}

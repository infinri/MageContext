<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\ExtractorInterface;
use Symfony\Component\Finder\Finder;

class ModuleGraphExtractor implements ExtractorInterface
{
    public function getName(): string
    {
        return 'module_graph';
    }

    public function getDescription(): string
    {
        return 'Builds module dependency graph from module.xml and config.php';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $modules = [];

        // Parse config.php for enabled/disabled status
        $enabledModules = $this->parseConfigPhp($repoPath);

        // Find all module.xml files within scopes
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('module.xml')
                ->path('/^[^\/]+\/[^\/]+\/etc\//');

            foreach ($finder as $file) {
                $parsed = $this->parseModuleXml($file->getRealPath(), $repoPath);
                if ($parsed === null) {
                    continue;
                }

                $moduleName = $parsed['name'];
                $parsed['status'] = isset($enabledModules[$moduleName])
                    ? ($enabledModules[$moduleName] ? 'enabled' : 'disabled')
                    : 'unknown';

                $modules[$moduleName] = $parsed;
            }
        }

        // Build adjacency data for graph visualization
        $edges = [];
        foreach ($modules as $moduleName => $module) {
            foreach ($module['dependencies'] as $dep) {
                $edges[] = [
                    'from' => $moduleName,
                    'to' => $dep,
                ];
            }
        }

        ksort($modules);

        return [
            'modules' => array_values($modules),
            'edges' => $edges,
            'summary' => [
                'total_modules' => count($modules),
                'total_dependencies' => count($edges),
                'modules_with_no_deps' => count(array_filter($modules, fn($m) => empty($m['dependencies']))),
            ],
        ];
    }

    private function parseModuleXml(string $filePath, string $repoPath): ?array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return null;
        }

        $moduleNode = $xml->module ?? null;
        if ($moduleNode === null) {
            return null;
        }

        $name = (string) ($moduleNode['name'] ?? '');
        if ($name === '') {
            return null;
        }

        $setupVersion = (string) ($moduleNode['setup_version'] ?? '');

        $dependencies = [];
        if (isset($moduleNode->sequence)) {
            foreach ($moduleNode->sequence->module as $dep) {
                $depName = (string) ($dep['name'] ?? '');
                if ($depName !== '') {
                    $dependencies[] = $depName;
                }
            }
        }

        // Derive relative path
        $relativePath = str_replace($repoPath . '/', '', dirname($filePath, 2));

        return [
            'name' => $name,
            'path' => $relativePath,
            'version' => $setupVersion,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Parse app/etc/config.php to get module enable/disable status.
     *
     * @return array<string, bool> module name => enabled
     */
    private function parseConfigPhp(string $repoPath): array
    {
        $configPath = $repoPath . '/app/etc/config.php';
        if (!is_file($configPath)) {
            return [];
        }

        $config = @include $configPath;
        if (!is_array($config) || !isset($config['modules'])) {
            return [];
        }

        $result = [];
        foreach ($config['modules'] as $moduleName => $status) {
            $result[$moduleName] = (bool) $status;
        }

        return $result;
    }
}

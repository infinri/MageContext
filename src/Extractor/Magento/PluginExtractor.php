<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\ExtractorInterface;
use Symfony\Component\Finder\Finder;

class PluginExtractor implements ExtractorInterface
{
    public function getName(): string
    {
        return 'plugins_interceptors';
    }

    public function getDescription(): string
    {
        return 'Extracts plugin/interceptor declarations and their method interceptions from di.xml';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $plugins = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('di.xml')
                ->sortByName();

            foreach ($finder as $file) {
                $diScope = $this->detectScope($file->getRelativePathname());
                $parsed = $this->parseDiXmlPlugins($file->getRealPath(), $repoPath, $diScope);
                foreach ($parsed as $plugin) {
                    $plugins[] = $plugin;
                }
            }
        }

        // Resolve plugin methods from PHP source files
        foreach ($plugins as &$plugin) {
            $plugin['methods'] = $this->resolvePluginMethods($repoPath, $plugin['plugin_class']);
            $plugin['targets_core'] = $this->isCoreClass($plugin['target_class']);
        }
        unset($plugin);

        // Build a chain view: group plugins by target class, sorted by sort_order
        $chains = $this->buildChains($plugins);

        return [
            'plugins' => $plugins,
            'chains' => $chains,
            'summary' => [
                'total_plugins' => count($plugins),
                'total_target_classes' => count($chains),
                'core_interceptions' => count(array_filter($plugins, fn($p) => $p['targets_core'])),
                'disabled_plugins' => count(array_filter($plugins, fn($p) => $p['disabled'])),
                'by_scope' => $this->countByScope($plugins),
            ],
        ];
    }

    private function parseDiXmlPlugins(string $filePath, string $repoPath, string $diScope): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return [];
        }

        $relativePath = str_replace($repoPath . '/', '', $filePath);
        $plugins = [];

        // <type name="Target\Class">
        //   <plugin name="plugin_name" type="Plugin\Class" sortOrder="10" disabled="false"/>
        // </type>
        foreach ($xml->xpath('//type') ?: [] as $typeNode) {
            $targetClass = (string) ($typeNode['name'] ?? '');
            if ($targetClass === '') {
                continue;
            }

            foreach ($typeNode->plugin ?? [] as $pluginNode) {
                $pluginName = (string) ($pluginNode['name'] ?? '');
                $pluginClass = (string) ($pluginNode['type'] ?? '');
                $sortOrder = ($pluginNode['sortOrder'] ?? null) !== null
                    ? (int) $pluginNode['sortOrder']
                    : null;
                $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                if ($pluginName === '') {
                    continue;
                }

                $plugins[] = [
                    'target_class' => $targetClass,
                    'plugin_name' => $pluginName,
                    'plugin_class' => $pluginClass,
                    'sort_order' => $sortOrder,
                    'disabled' => $disabled,
                    'scope' => $diScope,
                    'source_file' => $relativePath,
                    'methods' => [],
                    'targets_core' => false,
                ];
            }
        }

        return $plugins;
    }

    /**
     * Look at the plugin PHP class and find before/after/around methods.
     *
     * @return array<string> List of intercepted method descriptors, e.g. ["before::save", "around::execute"]
     */
    private function resolvePluginMethods(string $repoPath, string $pluginClass): array
    {
        if ($pluginClass === '') {
            return [];
        }

        $filePath = $this->classToFilePath($repoPath, $pluginClass);
        if ($filePath === null || !is_file($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $methods = [];
        // Match public function before|after|around followed by method name
        if (preg_match_all('/\bfunction\s+(before|after|around)([A-Z]\w*)\s*\(/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtolower($match[1]);
                $targetMethod = lcfirst($match[2]);
                $methods[] = $type . '::' . $targetMethod;
            }
        }

        return $methods;
    }

    private function classToFilePath(string $repoPath, string $className): ?string
    {
        // Convert PSR-4 class name to file path
        // e.g., Vendor\Module\Plugin\Something => app/code/Vendor/Module/Plugin/Something.php
        $relative = str_replace('\\', '/', $className) . '.php';

        // Try common Magento paths
        $candidates = [
            $repoPath . '/app/code/' . $relative,
            $repoPath . '/vendor/' . $this->toComposerPath($relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Fallback: search for the file
        $filename = basename($relative);
        $expectedEnd = '/' . $relative;

        $finder = new Finder();
        $finder->files()
            ->in($repoPath)
            ->name($filename)
            ->exclude(['vendor', '.git', 'dev', 'lib'])
            ->depth('< 10');

        foreach ($finder as $file) {
            if (str_ends_with(str_replace('\\', '/', $file->getRealPath()), $expectedEnd)) {
                return $file->getRealPath();
            }
        }

        return null;
    }

    private function toComposerPath(string $psr4Path): string
    {
        // Vendor/Module/rest → vendor-name/module-name/rest
        $parts = explode('/', $psr4Path);
        if (count($parts) >= 2) {
            $vendor = $this->toKebab($parts[0]);
            $module = $this->toKebab($parts[1]);
            $rest = array_slice($parts, 2);
            return $vendor . '/' . 'module-' . $module . '/' . implode('/', $rest);
        }
        return $psr4Path;
    }

    private function toKebab(string $str): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $str));
    }

    private function detectScope(string $relativePath): string
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

    private function isCoreClass(string $className): bool
    {
        return str_starts_with($className, 'Magento\\');
    }

    private function buildChains(array $plugins): array
    {
        $grouped = [];
        foreach ($plugins as $plugin) {
            if ($plugin['disabled']) {
                continue;
            }
            $target = $plugin['target_class'];
            $grouped[$target][] = $plugin;
        }

        $chains = [];
        foreach ($grouped as $target => $targetPlugins) {
            usort($targetPlugins, function ($a, $b) {
                $aOrder = $a['sort_order'] ?? PHP_INT_MAX;
                $bOrder = $b['sort_order'] ?? PHP_INT_MAX;
                return $aOrder <=> $bOrder;
            });

            $chains[$target] = array_map(fn($p) => [
                'plugin_name' => $p['plugin_name'],
                'plugin_class' => $p['plugin_class'],
                'sort_order' => $p['sort_order'],
                'scope' => $p['scope'],
                'methods' => $p['methods'],
            ], $targetPlugins);
        }

        ksort($chains);
        return $chains;
    }

    private function countByScope(array $plugins): array
    {
        $counts = [];
        foreach ($plugins as $p) {
            $scope = $p['scope'];
            $counts[$scope] = ($counts[$scope] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

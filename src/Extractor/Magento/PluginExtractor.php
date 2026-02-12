<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.2B: Plugin chains per method.
 *
 * For every intercepted method, produces an ordered chain of plugins with:
 * - method_id keying (FQCN::method)
 * - plugin_id (PluginFQCN::type::subject_method)
 * - evidence per declaration
 * - defines_methods (which before/after/around methods the plugin class defines)
 * - sort_order tie-break info
 */
class PluginExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'plugin_chains';
    }

    public function getDescription(): string
    {
        return 'Extracts plugin chains per method with evidence and canonical IDs';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
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
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $diScope = $this->detectScope($file->getRelativePathname());
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseDiXmlPlugins($file->getRealPath(), $repoPath, $diScope, $fileId, $ownerModule);
                foreach ($parsed as $plugin) {
                    $plugins[] = $plugin;
                }
            }
        }

        // Resolve plugin methods from PHP source files
        foreach ($plugins as &$plugin) {
            $resolved = $this->resolvePluginMethods($repoPath, $plugin['plugin_class']);
            $plugin['defines_methods'] = $resolved['defines_methods'];
            $plugin['intercepted_methods'] = $resolved['intercepted_methods'];
        }
        unset($plugin);

        // Build per-class chain view
        $classChains = $this->buildChains($plugins);

        // Build per-method chain view keyed by method_id
        $methodChains = $this->buildMethodChains($plugins);

        // Compute depth metrics
        $pluginDepthThreshold = $this->context
            ? ($this->config()->getThreshold('plugin_depth') ?? 5)
            : 5;

        $maxDepth = 0;
        $deepChains = [];
        foreach ($methodChains as $methodId => $chain) {
            $depth = count($chain['plugins']);
            if ($depth > $maxDepth) {
                $maxDepth = $depth;
            }
            if ($depth > $pluginDepthThreshold) {
                $deepChains[] = [
                    'method_id' => $methodId,
                    'depth' => $depth,
                ];
            }
        }
        usort($deepChains, fn($a, $b) => $b['depth'] <=> $a['depth']);

        $crossModuleCount = count(array_filter($plugins, fn($p) => $p['cross_module']));

        return [
            'plugins' => $plugins,
            'class_chains' => $classChains,
            'method_chains' => $methodChains,
            'summary' => [
                'total_plugins' => count($plugins),
                'total_target_classes' => count($classChains),
                'total_intercepted_methods' => count($methodChains),
                'max_plugin_depth' => $maxDepth,
                'deep_chains' => count($deepChains),
                'cross_module_plugins' => $crossModuleCount,
                'core_interceptions' => count(array_filter($plugins, fn($p) => $p['targets_core'])),
                'disabled_plugins' => count(array_filter($plugins, fn($p) => $p['disabled'])),
                'by_scope' => $this->countByScope($plugins),
            ],
            'deep_chains' => $deepChains,
        ];
    }

    private function parseDiXmlPlugins(string $filePath, string $repoPath, string $diScope, string $fileId, string $ownerModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'di.xml plugins');
            return [];
        }

        $plugins = [];

        foreach ($xml->xpath('//type') ?: [] as $typeNode) {
            $targetClass = IdentityResolver::normalizeFqcn((string) ($typeNode['name'] ?? ''));
            if ($targetClass === '') {
                continue;
            }

            foreach ($typeNode->plugin ?? [] as $pluginNode) {
                $pluginName = (string) ($pluginNode['name'] ?? '');
                $pluginClass = IdentityResolver::normalizeFqcn((string) ($pluginNode['type'] ?? ''));
                $sortOrder = ($pluginNode['sortOrder'] ?? null) !== null
                    ? (int) $pluginNode['sortOrder']
                    : null;
                $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                if ($pluginName === '') {
                    continue;
                }

                $targetClassId = IdentityResolver::classId($targetClass);
                $pluginClassId = $pluginClass !== '' ? IdentityResolver::classId($pluginClass) : '';
                $pluginModule = $pluginClass !== '' ? $this->resolveModule($pluginClass) : $ownerModule;
                $targetsCoreVal = IdentityResolver::isCoreClass($targetClass);
                $crossModule = $pluginClass !== '' && IdentityResolver::isCrossModule($pluginClass, $targetClass);

                $plugins[] = [
                    'target_class' => $targetClassId,
                    'plugin_name' => $pluginName,
                    'plugin_class' => $pluginClassId,
                    'sort_order' => $sortOrder,
                    'disabled' => $disabled,
                    'scope' => $diScope,
                    'module' => $pluginModule,
                    'declared_by' => $ownerModule,
                    'targets_core' => $targetsCoreVal,
                    'cross_module' => $crossModule,
                    'defines_methods' => [],
                    'intercepted_methods' => [],
                    'evidence' => [
                        Evidence::fromXml(
                            $fileId,
                            "plugin name={$pluginName} type={$pluginClassId} on {$targetClassId}"
                        )->toArray(),
                    ],
                ];
            }
        }

        return $plugins;
    }

    /**
     * Resolve plugin methods from PHP source: find before/after/around methods.
     *
     * @return array{defines_methods: string[], intercepted_methods: array<array{type: string, method: string, plugin_id: string}>}
     */
    private function resolvePluginMethods(string $repoPath, string $pluginClass): array
    {
        $result = ['defines_methods' => [], 'intercepted_methods' => []];

        if ($pluginClass === '') {
            return $result;
        }

        $filePath = $this->classToFilePath($repoPath, $pluginClass);
        if ($filePath === null || !is_file($filePath)) {
            $this->warnUnresolvedClass($pluginClass, 'plugin class file not found');
            return $result;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return $result;
        }

        // Match public function before|after|around followed by method name
        if (preg_match_all('/\bfunction\s+(before|after|around)([A-Z]\w*)\s*\(/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtolower($match[1]);
                $targetMethod = lcfirst($match[2]);
                $defineMethod = $match[1] . $match[2];
                $pluginId = IdentityResolver::pluginId($pluginClass, $type, $targetMethod);

                $result['defines_methods'][] = $defineMethod;
                $result['intercepted_methods'][] = [
                    'type' => $type,
                    'method' => $targetMethod,
                    'plugin_id' => $pluginId,
                ];
            }
        }

        sort($result['defines_methods']);

        return $result;
    }

    private function classToFilePath(string $repoPath, string $className): ?string
    {
        $relative = str_replace('\\', '/', $className) . '.php';

        $candidates = [
            $repoPath . '/app/code/' . $relative,
            $repoPath . '/vendor/' . $this->toComposerPath($relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toComposerPath(string $psr4Path): string
    {
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

    /**
     * Build per-method chains keyed by method_id (FQCN::method).
     * Each entry has ordered plugins sorted by sort_order.
     *
     * @return array<string, array{method_id: string, target_class: string, method: string, plugins: array}>
     */
    private function buildMethodChains(array $plugins): array
    {
        $methodMap = [];

        foreach ($plugins as $plugin) {
            if ($plugin['disabled']) {
                continue;
            }

            $target = $plugin['target_class'];

            foreach ($plugin['intercepted_methods'] as $im) {
                $methodId = IdentityResolver::methodId($target, $im['method']);

                if (!isset($methodMap[$methodId])) {
                    $methodMap[$methodId] = [
                        'method_id' => $methodId,
                        'target_class' => $target,
                        'method' => $im['method'],
                        'plugins' => [],
                    ];
                }

                $methodMap[$methodId]['plugins'][] = [
                    'plugin_id' => $im['plugin_id'],
                    'plugin_name' => $plugin['plugin_name'],
                    'plugin_class' => $plugin['plugin_class'],
                    'module' => $plugin['module'],
                    'type' => $im['type'],
                    'sort_order' => $plugin['sort_order'],
                    'scope' => $plugin['scope'],
                    'cross_module' => $plugin['cross_module'],
                    'evidence' => $plugin['evidence'],
                ];
            }
        }

        // Sort each method chain by sort_order
        foreach ($methodMap as &$entry) {
            usort($entry['plugins'], function ($a, $b) {
                $aOrder = $a['sort_order'] ?? PHP_INT_MAX;
                $bOrder = $b['sort_order'] ?? PHP_INT_MAX;
                return $aOrder <=> $bOrder;
            });
        }
        unset($entry);

        ksort($methodMap);
        return $methodMap;
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
                'module' => $p['module'],
                'defines_methods' => $p['defines_methods'],
                'evidence' => $p['evidence'],
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

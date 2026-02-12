<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.1A: Module inventory.
 *
 * For every discovered module/theme/package:
 * - module_id, type, path(s), enabled, area_presence
 * - composer metadata (name, require, autoload psr-4)
 * - module.xml sequence deps, registration.php presence
 * - files_count, php_files_count, primary_namespaces
 * - evidence
 */
class ModuleGraphExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'modules';
    }

    public function getDescription(): string
    {
        return 'Discovers modules, composer packages, and themes with dependency graph';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $modules = [];

        // Parse config.php for enabled/disabled status
        $enabledModules = $this->parseConfigPhp($repoPath);

        // 1. Discover Magento modules from module.xml
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                $this->warnGeneral("Scope path does not exist: {$scope}");
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('module.xml')
                ->path('/^[^\/]+\/[^\/]+\/etc\//')
                ->sortByName();

            foreach ($finder as $file) {
                $parsed = $this->parseModuleXml($file->getRealPath(), $repoPath);
                if ($parsed === null) {
                    $this->warnInvalidXml($this->fileId($file->getRealPath(), $repoPath), 'module.xml');
                    continue;
                }

                $moduleId = $parsed['module_id'];
                $modulePath = $repoPath . '/' . $parsed['path'];

                // Enabled status from config.php
                $parsed['enabled'] = $enabledModules[$moduleId] ?? null;
                $enabledSource = is_file($repoPath . '/app/etc/config.php') ? 'app/etc/config.php' : '';
                if ($parsed['enabled'] !== null && $enabledSource !== '') {
                    $parsed['evidence'][] = Evidence::fromXml(
                        $enabledSource,
                        'enabled=' . ($parsed['enabled'] ? 'true' : 'false'),
                    )->toArray();
                }

                // Detect area presence
                $parsed['area_presence'] = $this->detectAreaPresence($modulePath);

                // Composer metadata
                $parsed['composer_metadata'] = $this->parseModuleComposerMetadata($modulePath, $repoPath);

                // registration.php presence
                $parsed['has_registration'] = is_file($modulePath . '/registration.php');

                // File counts
                $fileCounts = $this->countFiles($modulePath);
                $parsed['files_count'] = $fileCounts['total'];
                $parsed['php_files_count'] = $fileCounts['php'];

                // Primary namespaces (from composer autoload or convention)
                $parsed['primary_namespaces'] = $this->resolveNamespaces($moduleId, $parsed['composer_metadata']);

                $modules[$moduleId] = $parsed;
            }
        }

        // 2. Discover themes from app/design
        $themes = $this->discoverThemes($repoPath, $scopes);
        foreach ($themes as $theme) {
            $modules[$theme['module_id']] = $theme;
        }

        // 3. Discover composer packages from root composer.lock
        $composerPackages = $this->discoverComposerPackages($repoPath);

        // Build adjacency data for graph visualization
        $edges = [];
        foreach ($modules as $moduleId => $module) {
            foreach ($module['sequence_dependencies'] as $dep) {
                $edges[] = [
                    'from' => $moduleId,
                    'to' => $dep,
                    'edge_type' => 'module_sequence',
                ];
            }
        }

        ksort($modules);

        $byType = [];
        foreach ($modules as $m) {
            $type = $m['type'];
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'modules' => array_values($modules),
            'composer_packages' => $composerPackages,
            'edges' => $edges,
            'summary' => [
                'total_modules' => count($modules),
                'total_composer_packages' => count($composerPackages),
                'total_sequence_deps' => count($edges),
                'modules_with_no_deps' => count(array_filter($modules, fn($m) => empty($m['sequence_dependencies']))),
                'by_type' => $byType,
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
        $fileId = $this->fileId($filePath, $repoPath);

        $sequenceDeps = [];
        if (isset($moduleNode->sequence)) {
            foreach ($moduleNode->sequence->module as $dep) {
                $depName = (string) ($dep['name'] ?? '');
                if ($depName !== '') {
                    $sequenceDeps[] = $depName;
                }
            }
        }
        sort($sequenceDeps);

        // Derive relative path (module root is 2 dirs up from etc/module.xml)
        $relativePath = str_replace($repoPath . '/', '', dirname($filePath, 2));

        return [
            'module_id' => $name,
            'name' => $name,
            'type' => 'magento_module',
            'path' => $relativePath,
            'area_presence' => [],
            'enabled' => null,
            'version' => $setupVersion,
            'sequence_dependencies' => $sequenceDeps,
            'composer_metadata' => null,
            'has_registration' => false,
            'files_count' => 0,
            'php_files_count' => 0,
            'primary_namespaces' => [],
            'evidence' => [
                Evidence::fromXml($fileId, "module.xml declares module '{$name}'")->toArray(),
            ],
        ];
    }

    /**
     * Detect area presence per spec \u00a73.1A.
     * Checks etc/ subdirectories, view/ directories, and Console/Command for console area.
     *
     * @return array<string>
     */
    private function detectAreaPresence(string $modulePath): array
    {
        $areas = [];
        $etcPath = $modulePath . '/etc';

        if (!is_dir($etcPath)) {
            return ['global'];
        }

        $areaNames = ['frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab'];
        foreach ($areaNames as $area) {
            if (is_dir($etcPath . '/' . $area)) {
                $areas[] = $area;
            }
        }

        // Check for frontend/adminhtml templates/layout
        if (is_dir($modulePath . '/view/frontend') && !in_array('frontend', $areas, true)) {
            $areas[] = 'frontend';
        }
        if (is_dir($modulePath . '/view/adminhtml') && !in_array('adminhtml', $areas, true)) {
            $areas[] = 'adminhtml';
        }

        // Console area: check for Console/Command directory
        if (is_dir($modulePath . '/Console') || is_dir($modulePath . '/Console/Command')) {
            $areas[] = 'console';
        }

        // Always include global since etc/di.xml etc. are global scope
        if (!in_array('global', $areas, true)) {
            array_unshift($areas, 'global');
        }

        sort($areas);

        return $areas;
    }

    /**
     * Parse a module's composer.json for full metadata per spec \u00a73.1A.
     */
    private function parseModuleComposerMetadata(string $modulePath, string $repoPath): ?array
    {
        $composerPath = $modulePath . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }

        $composer = @json_decode(file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            $this->warnGeneral('Invalid composer.json: ' . $this->fileId($composerPath, $repoPath));
            return null;
        }

        return [
            'name' => $composer['name'] ?? null,
            'require' => array_keys($composer['require'] ?? []),
            'autoload_psr4' => $composer['autoload']['psr-4'] ?? [],
        ];
    }

    /**
     * Count total files and PHP files in a module directory.
     *
     * @return array{total: int, php: int}
     */
    private function countFiles(string $modulePath): array
    {
        if (!is_dir($modulePath)) {
            return ['total' => 0, 'php' => 0];
        }

        $total = 0;
        $php = 0;

        $finder = new Finder();
        $finder->files()->in($modulePath)->sortByName();

        foreach ($finder as $file) {
            $total++;
            if ($file->getExtension() === 'php') {
                $php++;
            }
        }

        return ['total' => $total, 'php' => $php];
    }

    /**
     * Resolve primary namespaces for a module.
     */
    private function resolveNamespaces(string $moduleId, ?array $composerMeta): array
    {
        // Priority 1: from composer autoload PSR-4
        if ($composerMeta !== null && !empty($composerMeta['autoload_psr4'])) {
            $namespaces = array_keys($composerMeta['autoload_psr4']);
            return array_map(fn($ns) => rtrim($ns, '\\'), $namespaces);
        }

        // Priority 2: convention from module_id
        $parts = explode('_', $moduleId, 2);
        if (count($parts) === 2) {
            return [$parts[0] . '\\' . $parts[1]];
        }

        return [];
    }

    /**
     * Discover themes from app/design directories.
     *
     * @return array<array>
     */
    private function discoverThemes(string $repoPath, array $scopes): array
    {
        $themes = [];

        foreach ($scopes as $scope) {
            if (!str_contains($scope, 'design')) {
                continue;
            }

            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('theme.xml')
                ->depth('< 5')
                ->sortByName();

            foreach ($finder as $file) {
                $theme = $this->parseThemeXml($file->getRealPath(), $repoPath);
                if ($theme !== null) {
                    $themes[] = $theme;
                }
            }
        }

        return $themes;
    }

    private function parseThemeXml(string $filePath, string $repoPath): ?array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return null;
        }

        $title = (string) ($xml->title ?? '');
        $parent = (string) ($xml->parent ?? '');
        $relativePath = str_replace($repoPath . '/', '', dirname($filePath));
        $fileId = $this->fileId($filePath, $repoPath);

        // Derive theme name from path: app/design/frontend/Vendor/theme → Vendor/theme
        $pathParts = explode('/', $relativePath);
        $area = 'frontend';
        $themeName = $relativePath;
        if (count($pathParts) >= 4) {
            $area = $pathParts[2] ?? 'frontend';
            $themeName = implode('/', array_slice($pathParts, 3));
        }

        if ($title === '' && $themeName === '') {
            return null;
        }

        $themeId = $themeName ?: $title;

        $sequenceDeps = [];
        if ($parent !== '') {
            $sequenceDeps[] = $parent;
        }

        $fileCounts = $this->countFiles(dirname($filePath));

        return [
            'module_id' => $themeId,
            'name' => $themeId,
            'type' => 'theme',
            'path' => $relativePath,
            'area_presence' => [$area],
            'enabled' => true,
            'version' => '',
            'sequence_dependencies' => $sequenceDeps,
            'composer_metadata' => null,
            'has_registration' => is_file(dirname($filePath) . '/registration.php'),
            'files_count' => $fileCounts['total'],
            'php_files_count' => $fileCounts['php'],
            'primary_namespaces' => [],
            'title' => $title,
            'parent' => $parent,
            'evidence' => [
                Evidence::fromXml($fileId, "theme.xml declares theme '{$themeId}'")->toArray(),
            ],
        ];
    }

    /**
     * Discover Magento-related composer packages from composer.lock.
     *
     * @return array<array>
     */
    private function discoverComposerPackages(string $repoPath): array
    {
        $lockPath = $repoPath . '/composer.lock';
        if (!is_file($lockPath)) {
            return [];
        }

        $lock = @json_decode(file_get_contents($lockPath), true);
        if (!is_array($lock)) {
            $this->warnGeneral('Invalid composer.lock');
            return [];
        }

        $packages = [];
        foreach ($lock['packages'] ?? [] as $pkg) {
            $name = $pkg['name'] ?? '';
            // Only include Magento-ecosystem packages
            if (!$this->isMagentoPackage($name)) {
                continue;
            }

            $packages[] = [
                'package_id' => IdentityResolver::packageId($name),
                'name' => $name,
                'version' => $pkg['version'] ?? '',
                'type' => $pkg['type'] ?? 'library',
                'description' => $pkg['description'] ?? '',
                'require' => array_keys($pkg['require'] ?? []),
                'evidence' => [
                    Evidence::fromComposer('composer.lock', "Package '{$name}' found in composer.lock")->toArray(),
                ],
            ];
        }

        usort($packages, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $packages;
    }

    private function isMagentoPackage(string $name): bool
    {
        $prefixes = ['magento/', 'amasty/', 'mageworx/', 'aheadworks/', 'bss/', 'magesuite/'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
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
            $this->warnGeneral('Could not parse app/etc/config.php');
            return [];
        }

        $result = [];
        foreach ($config['modules'] as $moduleName => $status) {
            $result[$moduleName] = (bool) $status;
        }

        return $result;
    }
}

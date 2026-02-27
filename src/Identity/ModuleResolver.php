<?php

declare(strict_types=1);

namespace MageContext\Identity;

use Symfony\Component\Finder\Finder;

/**
 * Centralized class→module and file→module mapper.
 *
 * Spec §6.1: "Build a class->module mapper first."
 * Sources (priority order):
 *   1) PSR-4 autoload in module composer.json
 *   2) registration.php / module.xml path conventions
 *   3) fallback: file path "app/code/Vendor/Module/..."
 *
 * This service is initialized once per compilation and shared by all extractors.
 * It eliminates the ad-hoc resolveModuleFromClass/resolveModuleFromPath methods
 * that were duplicated across 8+ extractors.
 */
class ModuleResolver
{
    /**
     * PSR-4 namespace prefix → module_id mapping.
     * E.g., "Vendor\Module\" → "Vendor_Module"
     * Sorted by longest prefix first for correct matching.
     *
     * @var array<string, string>
     */
    private array $psr4Map = [];

    /**
     * module_id → module metadata.
     *
     * @var array<string, array{
     *   module_id: string,
     *   path: string,
     *   namespaces: string[],
     *   has_registration: bool,
     *   has_module_xml: bool,
     *   composer_name: ?string,
     *   type: string
     * }>
     */
    private array $modules = [];

    /**
     * file_id → module_id cache.
     *
     * @var array<string, string>
     */
    private array $fileCache = [];

    /**
     * class_id → module_id cache.
     *
     * @var array<string, string>
     */
    private array $classCache = [];

    /**
     * PSR-4 namespace prefix → absolute base directory.
     * Used by resolveClassFile() to map FQCN → file path.
     * Sorted by longest prefix first (parallel to $psr4Map).
     *
     * @var array<string, string>
     */
    private array $psr4Dirs = [];

    private string $repoPath;
    private bool $built = false;

    public function __construct(string $repoPath)
    {
        $this->repoPath = rtrim($repoPath, '/');
    }

    /**
     * Build the module map by scanning the repository.
     * Must be called before any resolution methods.
     *
     * @param string[] $scopes Directories to scan (e.g., ['app/code', 'app/design'])
     */
    public function build(array $scopes): void
    {
        $this->modules = [];
        $this->psr4Map = [];
        $this->psr4Dirs = [];
        $this->fileCache = [];
        $this->classCache = [];

        foreach ($scopes as $scope) {
            $scopePath = $this->repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // Priority 1: PSR-4 autoload from per-module composer.json
            $this->scanComposerAutoload($scopePath, $scope);

            // Priority 2: registration.php + module.xml conventions
            $this->scanModuleXml($scopePath, $scope);
        }

        // Sort PSR-4 map by longest prefix first (most specific match wins)
        uksort($this->psr4Map, fn(string $a, string $b) => strlen($b) <=> strlen($a));
        uksort($this->psr4Dirs, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        $this->built = true;
    }

    /**
     * Resolve a fully-qualified class name to a module_id.
     *
     * @return string module_id or "unknown"
     */
    public function resolveClass(string $fqcn): string
    {
        $normalized = IdentityResolver::normalizeFqcn($fqcn);

        if (isset($this->classCache[$normalized])) {
            return $this->classCache[$normalized];
        }

        // Priority 1: PSR-4 prefix match
        foreach ($this->psr4Map as $prefix => $moduleId) {
            if (str_starts_with($normalized, $prefix)) {
                $this->classCache[$normalized] = $moduleId;
                return $moduleId;
            }
        }

        // Priority 2: Convention-based (first two namespace segments)
        $result = IdentityResolver::moduleIdFromClass($normalized);
        $this->classCache[$normalized] = $result;
        return $result;
    }

    /**
     * Resolve a repo-relative file path to a module_id.
     *
     * @return string module_id or "unknown"
     */
    public function resolveFile(string $relativePath): string
    {
        if (isset($this->fileCache[$relativePath])) {
            return $this->fileCache[$relativePath];
        }

        $result = IdentityResolver::moduleIdFromPath($relativePath);
        $this->fileCache[$relativePath] = $result;
        return $result;
    }

    /**
     * Resolve an absolute file path to a module_id.
     *
     * @return string module_id or "unknown"
     */
    public function resolveAbsolutePath(string $absolutePath): string
    {
        $relative = IdentityResolver::fileId($absolutePath, $this->repoPath);
        return $this->resolveFile($relative);
    }

    /**
     * Get the module metadata for a given module_id.
     *
     * @return array|null Module metadata or null if not found
     */
    public function getModule(string $moduleId): ?array
    {
        return $this->modules[$moduleId] ?? null;
    }

    /**
     * Get all discovered modules.
     *
     * @return array<string, array>
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * Get the PSR-4 namespace map.
     *
     * @return array<string, string> namespace prefix → module_id
     */
    public function getPsr4Map(): array
    {
        return $this->psr4Map;
    }

    /**
     * Check if the resolver has been built.
     */
    public function isBuilt(): bool
    {
        return $this->built;
    }

    /**
     * Check if a class belongs to Magento core namespaces.
     */
    public function isCoreClass(string $fqcn): bool
    {
        return IdentityResolver::isCoreClass($fqcn);
    }

    /**
     * Get the count of discovered classes (for integrity score denominator).
     * Uses the same symbol discovery as the dependency graph.
     * Returns classCache size as proxy — classes are cached as they're resolved.
     */
    public function getDiscoveredClassCount(): int
    {
        return max(1, count($this->classCache));
    }

    /**
     * Check if two classes belong to different modules.
     */
    public function isCrossModule(string $classA, string $classB): bool
    {
        $modA = $this->resolveClass($classA);
        $modB = $this->resolveClass($classB);
        return $modA !== $modB && $modA !== 'unknown' && $modB !== 'unknown';
    }

    /**
     * Scan for per-module composer.json files and extract PSR-4 autoload mappings.
     */
    private function scanComposerAutoload(string $scopePath, string $scope): void
    {
        $finder = new Finder();
        $finder->files()
            ->in($scopePath)
            ->name('composer.json')
            ->depth('< 3')
            ->sortByName();

        foreach ($finder as $file) {
            $json = @json_decode(file_get_contents($file->getRealPath()), true);
            if ($json === null) {
                continue;
            }

            $composerName = $json['name'] ?? null;
            $psr4 = $json['autoload']['psr-4'] ?? [];

            // Always store PSR-4 dirs for class→file resolution,
            // even for packages where moduleIdFromPath returns 'unknown'
            $moduleAbsPath = dirname($file->getRealPath());
            foreach ($psr4 as $nsPrefix => $nsPaths) {
                $normalizedPrefix = rtrim($nsPrefix, '\\') . '\\';
                $srcDir = is_array($nsPaths) ? ($nsPaths[0] ?? '') : $nsPaths;
                $srcDir = rtrim($srcDir, '/');
                $this->psr4Dirs[$normalizedPrefix] = $srcDir !== ''
                    ? $moduleAbsPath . '/' . $srcDir
                    : $moduleAbsPath;
            }

            $relativePath = trim($scope, '/') . '/' . $file->getRelativePath();
            $moduleId = IdentityResolver::moduleIdFromPath($relativePath . '/');

            // Fallback: derive module_id from PSR-4 namespace (handles vendor/ paths)
            if ($moduleId === 'unknown' && !empty($psr4)) {
                $firstNs = array_key_first($psr4);
                if ($firstNs !== null) {
                    $moduleId = IdentityResolver::moduleIdFromClass(rtrim($firstNs, '\\'));
                }
            }

            if ($moduleId === 'unknown') {
                continue;
            }

            $namespaces = [];
            foreach ($psr4 as $nsPrefix => $nsPaths) {
                $nsPrefix = rtrim($nsPrefix, '\\') . '\\';
                $this->psr4Map[$nsPrefix] = $moduleId;
                $namespaces[] = rtrim($nsPrefix, '\\');
            }

            if (!isset($this->modules[$moduleId])) {
                $this->modules[$moduleId] = [
                    'module_id' => $moduleId,
                    'path' => $relativePath,
                    'namespaces' => $namespaces,
                    'has_registration' => false,
                    'has_module_xml' => false,
                    'composer_name' => $composerName,
                    'type' => 'magento_module',
                ];
            } else {
                $this->modules[$moduleId]['composer_name'] = $composerName;
                $this->modules[$moduleId]['namespaces'] = array_unique(
                    array_merge($this->modules[$moduleId]['namespaces'], $namespaces)
                );
            }
        }
    }

    /**
     * Resolve a fully-qualified class name to an absolute file path.
     * Uses PSR-4 directory mappings built from composer.json autoload entries.
     *
     * @return string|null Absolute file path, or null if not found
     */
    public function resolveClassFile(string $fqcn): ?string
    {
        $normalized = IdentityResolver::normalizeFqcn($fqcn);

        // Priority 1: PSR-4 prefix match (longest prefix wins)
        foreach ($this->psr4Dirs as $prefix => $baseDir) {
            if (str_starts_with($normalized, $prefix)) {
                $relative = substr($normalized, strlen($prefix));
                $filePath = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($filePath)) {
                    return $filePath;
                }
            }
        }

        // Priority 2: app/code convention fallback
        $conventionPath = $this->repoPath . '/app/code/' . str_replace('\\', '/', $normalized) . '.php';
        if (is_file($conventionPath)) {
            return $conventionPath;
        }

        return null;
    }

    /**
     * Scan for module.xml and registration.php to discover modules.
     */
    private function scanModuleXml(string $scopePath, string $scope): void
    {
        $finder = new Finder();
        $finder->files()
            ->in($scopePath)
            ->name('module.xml')
            ->path('/^[^\/]+\/[^\/]+\/etc\//')
            ->sortByName();

        foreach ($finder as $file) {
            $relativePath = trim($scope, '/') . '/' . $file->getRelativePath();
            // Strip /etc from path to get module root
            $modulePath = preg_replace('#/etc$#', '', $relativePath);

            $xml = @simplexml_load_file($file->getRealPath());
            if ($xml === false) {
                continue;
            }

            $moduleNode = $xml->module ?? null;
            if ($moduleNode === null) {
                continue;
            }

            $moduleId = (string) ($moduleNode['name'] ?? '');
            if ($moduleId === '') {
                $moduleId = IdentityResolver::moduleIdFromPath($relativePath . '/');
            }

            if ($moduleId === 'unknown') {
                continue;
            }

            // Check for registration.php
            $registrationPath = $this->repoPath . '/' . $modulePath . '/registration.php';
            $hasRegistration = is_file($registrationPath);

            if (!isset($this->modules[$moduleId])) {
                // Derive namespace from module_id convention
                $parts = explode('_', $moduleId, 2);
                $namespace = $parts[0] . '\\' . ($parts[1] ?? '');
                $nsPrefix = $namespace . '\\';

                $this->psr4Map[$nsPrefix] = $moduleId;
                $this->psr4Dirs[$nsPrefix] = $this->repoPath . '/' . $modulePath;

                $this->modules[$moduleId] = [
                    'module_id' => $moduleId,
                    'path' => $modulePath,
                    'namespaces' => [$namespace],
                    'has_registration' => $hasRegistration,
                    'has_module_xml' => true,
                    'composer_name' => null,
                    'type' => 'magento_module',
                ];
            } else {
                $this->modules[$moduleId]['has_module_xml'] = true;
                $this->modules[$moduleId]['has_registration'] = $hasRegistration;
            }
        }
    }
}

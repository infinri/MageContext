<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Extractor 1 — Call-Graph / Delegation Map Extractor.
 *
 * Extracts:
 * - All entry points for a feature area: frontend, REST, GraphQL, admin, guest, CLI
 * - The full delegation chain from entry point to the concrete class that executes
 * - Which classes delegate to which, and whether the concrete implementation is shared across contexts
 * - Whether GuestCouponManagementInterface delegates to the same concrete as CouponManagementInterface
 *
 * AI failure mode prevented:
 * Plugging a correct-looking class that is bypassed in REST, GraphQL, or guest checkout contexts.
 */
class CallGraphExtractor extends AbstractExtractor
{
    private const AREA_SCOPES = [
        'global', 'frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab',
    ];

    public function getName(): string
    {
        return 'call_graph';
    }

    public function getDescription(): string
    {
        return 'Builds delegation maps from entry points through DI to concrete executors, with cross-context correlation';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // 1. Load per-area DI preferences (interface → concrete, area-specific)
        $areaPreferences = $this->loadAreaPreferences($repoPath, $scopes);

        // 2. Load webapi.xml entry points (REST routes → service interface::method)
        $restEntryPoints = $this->loadRestEntryPoints($repoPath, $scopes);

        // 3. Load GraphQL resolvers
        $graphqlEntryPoints = $this->loadGraphqlEntryPoints($repoPath, $scopes);

        // 4. Load controller entry points (frontend + admin)
        $controllerEntryPoints = $this->loadControllerEntryPoints($repoPath, $scopes);

        // 5. Load CLI command entry points
        $cliEntryPoints = $this->loadCliEntryPoints($repoPath, $scopes);

        // 6. Build delegation chains for each entry point through DI preferences
        $delegationChains = [];

        foreach ($restEntryPoints as $ep) {
            $chain = $this->buildDelegationChain($ep, $areaPreferences, 'webapi_rest');
            $delegationChains[] = $chain;
        }

        foreach ($graphqlEntryPoints as $ep) {
            $chain = $this->buildDelegationChain($ep, $areaPreferences, 'graphql');
            $delegationChains[] = $chain;
        }

        foreach ($controllerEntryPoints as $ep) {
            $area = $ep['area'] ?? 'frontend';
            $chain = $this->buildDelegationChain($ep, $areaPreferences, $area);
            $delegationChains[] = $chain;
        }

        foreach ($cliEntryPoints as $ep) {
            $chain = $this->buildDelegationChain($ep, $areaPreferences, 'global');
            $delegationChains[] = $chain;
        }

        // 7. Cross-context correlation: find interfaces that resolve to the same concrete
        $sharedConcretes = $this->findSharedConcretes($areaPreferences);

        // 8. Find guest/authenticated delegation pairs
        $guestPairs = $this->findGuestAuthPairs($areaPreferences);

        // 9. Sort for determinism
        usort($delegationChains, fn($a, $b) => strcmp($a['entry_point_id'], $b['entry_point_id']));
        usort($sharedConcretes, fn($a, $b) => strcmp($a['concrete'], $b['concrete']));
        usort($guestPairs, fn($a, $b) => strcmp($a['guest_interface'], $b['guest_interface']));

        // Summary
        $totalEntryPoints = count($restEntryPoints) + count($graphqlEntryPoints)
            + count($controllerEntryPoints) + count($cliEntryPoints);

        return [
            'delegation_chains' => $delegationChains,
            'shared_concretes' => $sharedConcretes,
            'guest_auth_pairs' => $guestPairs,
            'area_preferences_summary' => $this->summarizeAreaPreferences($areaPreferences),
            'summary' => [
                'total_entry_points' => $totalEntryPoints,
                'rest_entry_points' => count($restEntryPoints),
                'graphql_entry_points' => count($graphqlEntryPoints),
                'controller_entry_points' => count($controllerEntryPoints),
                'cli_entry_points' => count($cliEntryPoints),
                'total_delegation_chains' => count($delegationChains),
                'shared_concrete_groups' => count($sharedConcretes),
                'guest_auth_pairs' => count($guestPairs),
                'chains_with_delegation' => count(array_filter($delegationChains, fn($c) => count($c['delegation_steps']) > 0)),
            ],
        ];
    }

    /**
     * Load DI preferences per area scope.
     *
     * @return array<string, array<string, string>> [area => [interface => concrete]]
     */
    private function loadAreaPreferences(string $repoPath, array $scopes): array
    {
        // Raw: [interface => [scope => concrete]]
        $raw = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $diScope = $this->detectDiScope($file->getRelativePathname());

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $fileId = $this->fileId($file->getRealPath(), $repoPath);
                    $this->warnInvalidXml($fileId, 'di.xml');
                    continue;
                }

                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = IdentityResolver::normalizeFqcn((string) ($node['for'] ?? ''));
                    $type = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($for === '' || $type === '') {
                        continue;
                    }
                    // Last writer wins per scope
                    $raw[$for][$diScope] = $type;
                }
            }
        }

        // Build per-area resolved map: global + area-specific overlay
        $areaPrefs = [];
        foreach (self::AREA_SCOPES as $area) {
            $areaPrefs[$area] = [];
        }

        foreach ($raw as $iface => $scopeMap) {
            $globalType = $scopeMap['global'] ?? null;

            foreach (self::AREA_SCOPES as $area) {
                if ($area === 'global') {
                    if ($globalType !== null) {
                        $areaPrefs['global'][$iface] = $globalType;
                    }
                } else {
                    // Area-specific overrides global
                    $resolved = $scopeMap[$area] ?? $globalType;
                    if ($resolved !== null) {
                        $areaPrefs[$area][$iface] = $resolved;
                    }
                }
            }
        }

        return $areaPrefs;
    }

    /**
     * Load REST entry points from webapi.xml.
     */
    private function loadRestEntryPoints(string $repoPath, array $scopes): array
    {
        $entryPoints = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('webapi.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());

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
                        $isAnonymous = in_array('anonymous', $resources, true);
                        $isSelf = in_array('self', $resources, true);

                        $entryPoints[] = [
                            'context' => 'rest',
                            'url' => $url,
                            'http_method' => $httpMethod,
                            'service_interface' => $serviceClass,
                            'service_method' => $serviceMethod,
                            'resources' => $resources,
                            'is_guest' => $isAnonymous,
                            'is_self' => $isSelf,
                            'module' => $module,
                            'source_file' => $fileId,
                            'evidence' => [
                                Evidence::fromXml($fileId, "REST {$httpMethod} {$url} -> {$serviceClass}::{$serviceMethod}")->toArray(),
                            ],
                        ];
                    }
                }
            }
        }

        return $entryPoints;
    }

    /**
     * Load GraphQL resolver entry points.
     */
    private function loadGraphqlEntryPoints(string $repoPath, array $scopes): array
    {
        $entryPoints = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('schema.graphqls')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                // Match field-level resolvers: fieldName: Type @resolver(class: "FQCN")
                $pattern = '/(\w+)\s*(?:\([^)]*\))?\s*:\s*\S+.*?@resolver\s*\(\s*class\s*:\s*"([^"]+)"\s*\)/';
                if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $fieldName = $match[1];
                        $resolverClass = str_replace('\\\\', '\\', $match[2]);

                        $entryPoints[] = [
                            'context' => 'graphql',
                            'field' => $fieldName,
                            'service_interface' => IdentityResolver::normalizeFqcn($resolverClass),
                            'service_method' => 'resolve',
                            'module' => $module,
                            'source_file' => $fileId,
                            'evidence' => [
                                Evidence::fromXml($fileId, "GraphQL {$fieldName} -> {$resolverClass}::resolve")->toArray(),
                            ],
                        ];
                    }
                }
            }
        }

        return $entryPoints;
    }

    /**
     * Load controller entry points.
     */
    private function loadControllerEntryPoints(string $repoPath, array $scopes): array
    {
        $entryPoints = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('#Controller#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                $area = (str_contains($relativePath, '/Adminhtml/') || str_contains($relativePath, '/adminhtml/'))
                    ? 'adminhtml'
                    : 'frontend';

                $entryPoints[] = [
                    'context' => $area,
                    'service_interface' => IdentityResolver::normalizeFqcn($className),
                    'service_method' => 'execute',
                    'area' => $area,
                    'module' => $this->resolveModuleFromFile($file->getRealPath()),
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromPhpAst($fileId, 0, null, "controller {$className}::execute")->toArray(),
                    ],
                ];
            }
        }

        return $entryPoints;
    }

    /**
     * Load CLI command entry points.
     */
    private function loadCliEntryPoints(string $repoPath, array $scopes): array
    {
        $entryPoints = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('#Console#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                if (!str_contains($content, 'extends Command') && !str_contains($content, 'CommandInterface')) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $entryPoints[] = [
                    'context' => 'cli',
                    'service_interface' => IdentityResolver::normalizeFqcn($className),
                    'service_method' => 'execute',
                    'module' => $this->resolveModuleFromFile($file->getRealPath()),
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromPhpAst($fileId, 0, null, "CLI {$className}::execute")->toArray(),
                    ],
                ];
            }
        }

        return $entryPoints;
    }

    /**
     * Build delegation chain for an entry point by following DI preferences.
     */
    private function buildDelegationChain(array $entryPoint, array $areaPreferences, string $area): array
    {
        $serviceInterface = $entryPoint['service_interface'] ?? '';
        $serviceMethod = $entryPoint['service_method'] ?? 'execute';
        $context = $entryPoint['context'] ?? $area;

        $entryPointId = $context . '::' . $serviceInterface . '::' . $serviceMethod;
        if (isset($entryPoint['url'])) {
            $entryPointId = $context . '::' . $entryPoint['http_method'] . '::' . $entryPoint['url'];
        } elseif (isset($entryPoint['field'])) {
            $entryPointId = 'graphql::' . $entryPoint['field'];
        }

        // Resolve delegation chain through DI preferences
        $steps = [];
        $current = $serviceInterface;
        $seen = [];
        $areaMap = $areaPreferences[$area] ?? $areaPreferences['global'] ?? [];

        while (isset($areaMap[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $concrete = $areaMap[$current];
            $steps[] = [
                'from' => $current,
                'to' => $concrete,
                'resolution' => 'di_preference',
                'area' => $area,
            ];
            $current = $concrete;
        }

        // The final concrete class
        $finalConcrete = $current;

        // Check if the same interface resolves differently in other areas
        $crossAreaResolutions = [];
        foreach (self::AREA_SCOPES as $otherArea) {
            if ($otherArea === $area) {
                continue;
            }
            $otherMap = $areaPreferences[$otherArea] ?? [];
            if (isset($otherMap[$serviceInterface])) {
                $otherConcrete = $this->resolveChain($serviceInterface, $otherMap);
                if ($otherConcrete !== $finalConcrete) {
                    $crossAreaResolutions[$otherArea] = $otherConcrete;
                }
            }
        }

        return [
            'entry_point_id' => $entryPointId,
            'context' => $context,
            'service_interface' => $serviceInterface,
            'service_method' => $serviceMethod,
            'final_concrete' => $finalConcrete,
            'delegation_steps' => $steps,
            'delegation_depth' => count($steps),
            'cross_area_differences' => $crossAreaResolutions,
            'has_cross_area_divergence' => !empty($crossAreaResolutions),
            'module' => $entryPoint['module'] ?? 'unknown',
            'url' => $entryPoint['url'] ?? null,
            'http_method' => $entryPoint['http_method'] ?? null,
            'graphql_field' => $entryPoint['field'] ?? null,
            'is_guest' => $entryPoint['is_guest'] ?? false,
            'resources' => $entryPoint['resources'] ?? [],
            'source_file' => $entryPoint['source_file'] ?? '',
            'evidence' => $entryPoint['evidence'] ?? [],
        ];
    }

    /**
     * Follow a DI preference chain to the final concrete.
     */
    private function resolveChain(string $interface, array $prefMap): string
    {
        $current = $interface;
        $seen = [];
        while (isset($prefMap[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $current = $prefMap[$current];
        }
        return $current;
    }

    /**
     * Find interfaces across areas that resolve to the same concrete class.
     * This reveals shared implementation paths that the AI must reason about.
     */
    private function findSharedConcretes(array $areaPreferences): array
    {
        // Collect: concrete → [interface → [areas]]
        $concreteMap = [];

        foreach (self::AREA_SCOPES as $area) {
            foreach ($areaPreferences[$area] ?? [] as $iface => $concrete) {
                $finalConcrete = $this->resolveChain($iface, $areaPreferences[$area]);
                $concreteMap[$finalConcrete][$iface][] = $area;
            }
        }

        // Only keep concretes shared by multiple interfaces
        $shared = [];
        foreach ($concreteMap as $concrete => $interfaces) {
            if (count($interfaces) < 2) {
                continue;
            }

            $interfaceList = [];
            foreach ($interfaces as $iface => $areas) {
                $interfaceList[] = [
                    'interface' => $iface,
                    'areas' => array_values(array_unique($areas)),
                ];
            }
            usort($interfaceList, fn($a, $b) => strcmp($a['interface'], $b['interface']));

            $shared[] = [
                'concrete' => $concrete,
                'concrete_module' => $this->resolveModule($concrete),
                'interfaces' => $interfaceList,
                'interface_count' => count($interfaceList),
                'note' => "Multiple interfaces delegate to the same concrete class. "
                    . "Changes to {$concrete} affect all listed interfaces.",
            ];
        }

        return $shared;
    }

    /**
     * Find Guest/Authenticated interface delegation pairs.
     *
     * Pattern: GuestFooInterface and FooInterface both resolve to the same concrete,
     * or GuestFooManagement delegates to FooManagement which delegates to the real impl.
     */
    private function findGuestAuthPairs(array $areaPreferences): array
    {
        $pairs = [];
        $globalPrefs = $areaPreferences['global'] ?? [];
        $restPrefs = $areaPreferences['webapi_rest'] ?? [];
        $merged = array_merge($globalPrefs, $restPrefs);

        // Find Guest* interfaces
        foreach ($merged as $iface => $concrete) {
            if (!preg_match('/\\\\Guest(\w+)Interface$/', $iface, $m)) {
                continue;
            }

            // Find the non-guest counterpart
            $authCounterpart = str_replace('\\Guest' . $m[1] . 'Interface', '\\' . $m[1] . 'Interface', $iface);

            if (!isset($merged[$authCounterpart])) {
                continue;
            }

            $guestConcrete = $this->resolveChain($iface, $merged);
            $authConcrete = $this->resolveChain($authCounterpart, $merged);

            $pairs[] = [
                'guest_interface' => $iface,
                'auth_interface' => $authCounterpart,
                'guest_concrete' => $guestConcrete,
                'auth_concrete' => $authConcrete,
                'shared_concrete' => $guestConcrete === $authConcrete,
                'note' => $guestConcrete === $authConcrete
                    ? "Guest and authenticated paths share the same concrete implementation ({$guestConcrete}). "
                        . "Modifications affect both contexts."
                    : "Guest and authenticated paths use DIFFERENT implementations. "
                        . "Ensure changes are applied to both if needed.",
            ];
        }

        return $pairs;
    }

    /**
     * Summarize area preferences: per-area count + interfaces with area-specific overrides.
     */
    private function summarizeAreaPreferences(array $areaPreferences): array
    {
        $summary = [];
        foreach (self::AREA_SCOPES as $area) {
            $summary[$area] = count($areaPreferences[$area] ?? []);
        }

        // Find interfaces with area-specific overrides (different from global)
        $areaOverrides = [];
        $globalPrefs = $areaPreferences['global'] ?? [];
        foreach (self::AREA_SCOPES as $area) {
            if ($area === 'global') {
                continue;
            }
            foreach ($areaPreferences[$area] ?? [] as $iface => $concrete) {
                $globalConcrete = $globalPrefs[$iface] ?? null;
                if ($globalConcrete !== null && $globalConcrete !== $concrete) {
                    $areaOverrides[] = [
                        'interface' => $iface,
                        'area' => $area,
                        'global_concrete' => $globalConcrete,
                        'area_concrete' => $concrete,
                    ];
                }
            }
        }

        usort($areaOverrides, fn($a, $b) => strcmp($a['interface'], $b['interface']));

        return [
            'preferences_per_area' => $summary,
            'area_overrides' => $areaOverrides,
            'total_area_overrides' => count($areaOverrides),
        ];
    }

    /**
     * Extract FQCN from PHP file content.
     */
    private function extractClassName(string $content): ?string
    {
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $content, $nsMatch)) {
            $namespace = $nsMatch[1];
        }

        if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            $class = $classMatch[1];
        }

        if ($namespace !== '' && $class !== '') {
            return $namespace . '\\' . $class;
        }

        return null;
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

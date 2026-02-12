<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.2A: DI Resolution Map.
 *
 * For every DI target (interface/class), produces a per-area ordered resolution
 * chain showing the final_resolved_type, each resolution_step with evidence,
 * and an aggregate confidence score.
 *
 * Resolution order: global di.xml -> area-specific di.xml (last wins).
 */
class DiPreferenceExtractor extends AbstractExtractor
{
    private const AREA_SCOPES = [
        'global', 'frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab',
    ];

    public function getName(): string
    {
        return 'di_resolution_map';
    }

    public function getDescription(): string
    {
        return 'Builds per-area DI resolution map with resolution chains, confidence, and evidence';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // Collect raw declarations from all di.xml files
        $rawPreferences = [];   // [interface => [scope => [{type, file, evidence}]]]
        $rawVirtualTypes = [];  // [name => [scope => [{type, file, args, evidence}]]]
        $proxies = [];
        $factories = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $diScope = $this->detectScope($file->getRelativePathname());
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $this->warnInvalidXml($fileId, 'di.xml');
                    continue;
                }

                // Preferences
                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = IdentityResolver::normalizeFqcn((string) ($node['for'] ?? ''));
                    $type = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($for === '' || $type === '') {
                        continue;
                    }

                    $rawPreferences[$for][$diScope][] = [
                        'type' => $type,
                        'module' => $ownerModule,
                        'evidence' => Evidence::fromXml(
                            $fileId,
                            "preference for={$for} type={$type}"
                        )->toArray(),
                    ];
                }

                // Virtual types
                foreach ($xml->xpath('//virtualType') ?: [] as $node) {
                    $name = (string) ($node['name'] ?? '');
                    $type = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $arguments = $this->extractArguments($node);

                    $rawVirtualTypes[$name][$diScope][] = [
                        'type' => $type,
                        'module' => $ownerModule,
                        'arguments' => $arguments,
                        'evidence' => Evidence::fromXml(
                            $fileId,
                            "virtualType name={$name} type={$type}"
                        )->toArray(),
                    ];
                }

                // Proxies and factories
                $pf = $this->parseProxiesAndFactories($xml, $diScope, $fileId, $ownerModule);
                foreach ($pf['proxies'] as $p) {
                    $proxies[] = $p;
                }
                foreach ($pf['factories'] as $f) {
                    $factories[] = $f;
                }
            }
        }

        // Build per-area resolution chains
        $resolutions = $this->buildResolutionChains($rawPreferences);

        // Build virtual type entries
        $virtualTypes = $this->buildVirtualTypeEntries($rawVirtualTypes);

        // Compute summary
        $coreOverrides = 0;
        foreach ($resolutions as $r) {
            if ($r['is_core_override']) {
                $coreOverrides++;
            }
        }

        $byScope = $this->countByScope($resolutions);

        return [
            'resolutions' => $resolutions,
            'virtual_types' => $virtualTypes,
            'proxies' => $proxies,
            'factories' => $factories,
            'summary' => [
                'total_resolutions' => count($resolutions),
                'total_virtual_types' => count($virtualTypes),
                'total_proxies' => count($proxies),
                'total_factories' => count($factories),
                'core_overrides' => $coreOverrides,
                'multi_area_targets' => count(array_filter($resolutions, fn($r) => count($r['per_area']) > 1)),
                'by_scope' => $byScope,
            ],
        ];
    }

    /**
     * Build per-area resolution chains from raw preference declarations.
     *
     * For each DI target (interface), produces:
     * - di_target_id
     * - is_core_override
     * - per_area: { area => { final_resolved_type, resolution_steps[], confidence } }
     * - evidence[]
     */
    private function buildResolutionChains(array $rawPreferences): array
    {
        $resolutions = [];

        foreach ($rawPreferences as $interface => $scopeDeclarations) {
            $targetId = IdentityResolver::diTargetId($interface);
            $isCoreOverride = IdentityResolver::isCoreClass($interface);

            $perArea = [];
            $allEvidence = [];

            // For each Magento area, compute resolution chain
            // Order: global -> area-specific (last wins)
            foreach (self::AREA_SCOPES as $area) {
                $steps = [];

                // Step 1: global preference (if any)
                if (isset($scopeDeclarations['global'])) {
                    foreach ($scopeDeclarations['global'] as $decl) {
                        $steps[] = [
                            'scope' => 'global',
                            'resolved_type' => $decl['type'],
                            'declared_by' => $decl['module'],
                            'evidence' => $decl['evidence'],
                        ];
                        $allEvidence[] = $decl['evidence'];
                    }
                }

                // Step 2: area-specific override (if any)
                if ($area !== 'global' && isset($scopeDeclarations[$area])) {
                    foreach ($scopeDeclarations[$area] as $decl) {
                        $steps[] = [
                            'scope' => $area,
                            'resolved_type' => $decl['type'],
                            'declared_by' => $decl['module'],
                            'evidence' => $decl['evidence'],
                        ];
                        $allEvidence[] = $decl['evidence'];
                    }
                }

                if (empty($steps)) {
                    continue;
                }

                // Last step wins
                $lastStep = end($steps);
                $finalType = $lastStep['resolved_type'];

                // Confidence: 1.0 for XML-declared, slightly lower if multiple
                $confidence = count($steps) === 1 ? 1.0 : 0.95;

                $perArea[$area] = [
                    'final_resolved_type' => $finalType,
                    'resolved_module' => $this->resolveModule($finalType),
                    'resolution_steps' => $steps,
                    'confidence' => $confidence,
                ];
            }

            if (empty($perArea)) {
                continue;
            }

            $resolutions[] = [
                'di_target_id' => $targetId,
                'interface' => $interface,
                'is_core_override' => $isCoreOverride,
                'per_area' => $perArea,
                'evidence' => $allEvidence,
            ];
        }

        // Sort by interface name for determinism
        usort($resolutions, fn($a, $b) => strcmp($a['interface'], $b['interface']));

        return $resolutions;
    }

    /**
     * Build virtual type entries with per-area resolution.
     */
    private function buildVirtualTypeEntries(array $rawVirtualTypes): array
    {
        $entries = [];

        foreach ($rawVirtualTypes as $name => $scopeDeclarations) {
            $perArea = [];
            $allEvidence = [];

            foreach (self::AREA_SCOPES as $area) {
                if ($area === 'global' && isset($scopeDeclarations['global'])) {
                    $last = end($scopeDeclarations['global']);
                    $perArea['global'] = [
                        'base_type' => $last['type'],
                        'declared_by' => $last['module'],
                        'arguments' => $last['arguments'],
                    ];
                    $allEvidence[] = $last['evidence'];
                } elseif ($area !== 'global' && isset($scopeDeclarations[$area])) {
                    $last = end($scopeDeclarations[$area]);
                    $perArea[$area] = [
                        'base_type' => $last['type'],
                        'declared_by' => $last['module'],
                        'arguments' => $last['arguments'],
                    ];
                    $allEvidence[] = $last['evidence'];
                }
            }

            if (empty($perArea)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'per_area' => $perArea,
                'evidence' => $allEvidence,
            ];
        }

        usort($entries, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $entries;
    }

    private function extractArguments(\SimpleXMLElement $node): array
    {
        $arguments = [];
        $argsNode = $node->arguments ?? null;
        if ($argsNode === null) {
            return $arguments;
        }

        foreach ($argsNode->argument ?? [] as $arg) {
            $name = (string) ($arg['name'] ?? '');
            $type = (string) ($arg['xsi:type'] ?? $arg->attributes('xsi', true)->type ?? 'string');
            if ($name !== '') {
                $arguments[] = [
                    'name' => $name,
                    'type' => $type,
                ];
            }
        }

        return $arguments;
    }

    private function detectScope(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);

        foreach (self::AREA_SCOPES as $area) {
            if ($area === 'global') {
                continue;
            }
            if (str_contains($normalized, '/etc/' . $area . '/di.xml')) {
                return $area;
            }
        }

        return 'global';
    }

    /**
     * Parse di.xml for Proxy and Factory usage in type constructor arguments.
     */
    private function parseProxiesAndFactories(\SimpleXMLElement $xml, string $diScope, string $fileId, string $ownerModule): array
    {
        $proxies = [];
        $factories = [];

        foreach ($xml->xpath('//type') ?: [] as $typeNode) {
            $typeName = IdentityResolver::normalizeFqcn((string) ($typeNode['name'] ?? ''));
            if ($typeName === '') {
                continue;
            }

            $argsNode = $typeNode->arguments ?? null;
            if ($argsNode === null) {
                continue;
            }

            foreach ($argsNode->argument ?? [] as $arg) {
                $argType = (string) ($arg->attributes('xsi', true)->type ?? '');
                if ($argType !== 'object') {
                    continue;
                }

                $value = trim((string) $arg);
                if ($value === '') {
                    continue;
                }

                $argName = (string) ($arg['name'] ?? '');

                if (str_ends_with($value, '\\Proxy') || str_ends_with($value, '\Proxy')) {
                    $proxies[] = [
                        'type' => $typeName,
                        'argument' => $argName,
                        'proxy_class' => $value,
                        'scope' => $diScope,
                        'declared_by' => $ownerModule,
                        'evidence' => [
                            Evidence::fromXml($fileId, "proxy {$value} for {$typeName}::{$argName}")->toArray(),
                        ],
                    ];
                } elseif (str_ends_with($value, 'Factory')) {
                    $factories[] = [
                        'type' => $typeName,
                        'argument' => $argName,
                        'factory_class' => $value,
                        'scope' => $diScope,
                        'declared_by' => $ownerModule,
                        'evidence' => [
                            Evidence::fromXml($fileId, "factory {$value} for {$typeName}::{$argName}")->toArray(),
                        ],
                    ];
                }
            }
        }

        return ['proxies' => $proxies, 'factories' => $factories];
    }

    private function countByScope(array $resolutions): array
    {
        $counts = [];
        foreach ($resolutions as $r) {
            foreach (array_keys($r['per_area']) as $area) {
                $counts[$area] = ($counts[$area] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }
}

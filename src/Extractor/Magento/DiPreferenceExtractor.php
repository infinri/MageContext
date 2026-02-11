<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\ExtractorInterface;
use Symfony\Component\Finder\Finder;

class DiPreferenceExtractor implements ExtractorInterface
{
    private const DI_SCOPES = [
        'global' => 'etc/di.xml',
        'frontend' => 'etc/frontend/di.xml',
        'adminhtml' => 'etc/adminhtml/di.xml',
        'webapi_rest' => 'etc/webapi_rest/di.xml',
        'webapi_soap' => 'etc/webapi_soap/di.xml',
        'graphql' => 'etc/graphql/di.xml',
        'crontab' => 'etc/crontab/di.xml',
    ];

    public function getName(): string
    {
        return 'di_preference_overrides';
    }

    public function getDescription(): string
    {
        return 'Extracts DI preferences and virtual types from all di.xml scopes';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $preferences = [];
        $virtualTypes = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // Find all di.xml files
            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('di.xml')
                ->sortByName();

            foreach ($finder as $file) {
                $diScope = $this->detectScope($file->getRelativePathname());
                $parsed = $this->parseDiXml($file->getRealPath(), $repoPath, $diScope);

                foreach ($parsed['preferences'] as $pref) {
                    $preferences[] = $pref;
                }
                foreach ($parsed['virtual_types'] as $vt) {
                    $virtualTypes[] = $vt;
                }
            }
        }

        // Flag core overrides
        foreach ($preferences as &$pref) {
            $pref['is_core_override'] = $this->isCoreClass($pref['interface']);
        }
        unset($pref);

        return [
            'preferences' => $preferences,
            'virtual_types' => $virtualTypes,
            'summary' => [
                'total_preferences' => count($preferences),
                'total_virtual_types' => count($virtualTypes),
                'core_overrides' => count(array_filter($preferences, fn($p) => $p['is_core_override'])),
                'by_scope' => $this->countByScope($preferences),
            ],
        ];
    }

    private function parseDiXml(string $filePath, string $repoPath, string $diScope): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return ['preferences' => [], 'virtual_types' => []];
        }

        $relativePath = str_replace($repoPath . '/', '', $filePath);
        $preferences = [];
        $virtualTypes = [];

        // Extract <preference for="..." type="..." />
        foreach ($xml->xpath('//preference') ?: [] as $node) {
            $for = (string) ($node['for'] ?? '');
            $type = (string) ($node['type'] ?? '');
            if ($for === '' || $type === '') {
                continue;
            }

            $preferences[] = [
                'interface' => $for,
                'preference' => $type,
                'scope' => $diScope,
                'source_file' => $relativePath,
                'is_core_override' => false,
            ];
        }

        // Extract <virtualType name="..." type="..." />
        foreach ($xml->xpath('//virtualType') ?: [] as $node) {
            $name = (string) ($node['name'] ?? '');
            $type = (string) ($node['type'] ?? '');
            if ($name === '') {
                continue;
            }

            $arguments = $this->extractArguments($node);

            $virtualTypes[] = [
                'name' => $name,
                'type' => $type,
                'scope' => $diScope,
                'source_file' => $relativePath,
                'arguments' => $arguments,
            ];
        }

        return [
            'preferences' => $preferences,
            'virtual_types' => $virtualTypes,
        ];
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

        foreach (self::DI_SCOPES as $scope => $pattern) {
            if ($scope === 'global' && preg_match('#/etc/di\.xml$#', $normalized)) {
                return 'global';
            }
            if ($scope !== 'global' && str_contains($normalized, '/etc/' . $scope . '/di.xml')) {
                return $scope;
            }
        }

        return 'global';
    }

    private function isCoreClass(string $className): bool
    {
        return str_starts_with($className, 'Magento\\');
    }

    private function countByScope(array $preferences): array
    {
        $counts = [];
        foreach ($preferences as $pref) {
            $scope = $pref['scope'];
            $counts[$scope] = ($counts[$scope] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

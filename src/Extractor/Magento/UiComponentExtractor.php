<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;

/**
 * UI component declarations with evidence.
 */
class UiComponentExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'ui_components';
    }

    public function getDescription(): string
    {
        return 'Extracts UI component definitions from ui_component XML files';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $components = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.xml')
                ->path('/ui_component/')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $area = $this->detectArea($file->getRelativePathname());
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseUiComponentXml($file->getRealPath(), $repoPath, $area, $fileId, $declaringModule);
                if ($parsed !== null) {
                    $components[] = $parsed;
                }
            }
        }

        return [
            'components' => $components,
            'summary' => [
                'total_components' => count($components),
                'by_type' => $this->countByField($components, 'type'),
                'by_area' => $this->countByField($components, 'area'),
            ],
        ];
    }

    private function parseUiComponentXml(string $filePath, string $repoPath, string $area, string $fileId, string $declaringModule): ?array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'UI component XML');
            return null;
        }

        $componentName = pathinfo($filePath, PATHINFO_FILENAME);
        $rootTag = $xml->getName();
        $evidence = Evidence::fromXml($fileId, "UI component '{$componentName}'")->toArray();

        $component = [
            'name' => $componentName,
            'type' => $rootTag,
            'area' => $area,
            'declared_by' => $declaringModule,
            'source_file' => $fileId,
            'data_sources' => [],
            'columns' => [],
            'fieldsets' => [],
            'buttons' => [],
            'evidence' => [$evidence],
        ];

        // Extract dataSource references
        foreach ($xml->xpath('//dataSource') ?: [] as $dsNode) {
            $dsName = (string) ($dsNode['name'] ?? '');
            if ($dsName !== '') {
                $component['data_sources'][] = $dsName;
            }
        }

        // Extract columns (for listing components)
        foreach ($xml->xpath('//column') ?: [] as $colNode) {
            $colName = (string) ($colNode['name'] ?? '');
            if ($colName !== '') {
                $component['columns'][] = [
                    'name' => $colName,
                    'class' => (string) ($colNode['class'] ?? ''),
                    'component' => (string) ($colNode['component'] ?? ''),
                ];
            }
        }

        // Extract fieldsets (for form components)
        foreach ($xml->xpath('//fieldset') ?: [] as $fsNode) {
            $fsName = (string) ($fsNode['name'] ?? '');
            if ($fsName !== '') {
                $fields = [];
                foreach ($fsNode->xpath('.//field') ?: [] as $fieldNode) {
                    $fieldName = (string) ($fieldNode['name'] ?? '');
                    if ($fieldName !== '') {
                        $fields[] = [
                            'name' => $fieldName,
                            'formElement' => (string) ($fieldNode['formElement'] ?? ''),
                        ];
                    }
                }
                $component['fieldsets'][] = [
                    'name' => $fsName,
                    'fields' => $fields,
                ];
            }
        }

        // Extract buttons
        foreach ($xml->xpath('//button') ?: [] as $btnNode) {
            $btnName = (string) ($btnNode['name'] ?? '');
            if ($btnName !== '') {
                $component['buttons'][] = $btnName;
            }
        }

        return $component;
    }

}

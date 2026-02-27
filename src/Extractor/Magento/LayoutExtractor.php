<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.3: Layout handles with evidence.
 */
class LayoutExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'layout_handles';
    }

    public function getDescription(): string
    {
        return 'Extracts layout XML handles, blocks, containers, and reference overrides';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $handles = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.xml')
                ->path('/layout/')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $area = $this->detectArea($file->getRelativePathname());
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseLayoutXml($file->getRealPath(), $repoPath, $area, $fileId, $declaringModule);
                foreach ($parsed as $handle) {
                    $handles[] = $handle;
                }
            }
        }

        $handleMap = $this->buildHandleMap($handles);

        return [
            'handles' => $handles,
            'handle_map' => $handleMap,
            'summary' => [
                'total_handles' => count($handles),
                'total_blocks' => $this->countType($handles, 'block'),
                'total_containers' => $this->countType($handles, 'container'),
                'total_references' => $this->countType($handles, 'referenceBlock')
                    + $this->countType($handles, 'referenceContainer'),
                'total_moves' => $this->countType($handles, 'move'),
                'total_removes' => $this->countType($handles, 'remove'),
                'by_area' => $this->countByField($handles, 'area'),
            ],
        ];
    }

    private function parseLayoutXml(string $filePath, string $repoPath, string $area, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'layout XML');
            return [];
        }

        $handleName = pathinfo($filePath, PATHINFO_FILENAME);
        $entries = [];
        $evidence = Evidence::fromXml($fileId, "layout handle '{$handleName}'")->toArray();

        // Extract blocks
        foreach ($xml->xpath('//block') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'block',
                'name' => (string) ($node['name'] ?? ''),
                'class' => (string) ($node['class'] ?? ''),
                'template' => (string) ($node['template'] ?? ''),
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'evidence' => [$evidence],
            ];
        }

        // Extract containers
        foreach ($xml->xpath('//container') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'container',
                'name' => (string) ($node['name'] ?? ''),
                'class' => '',
                'template' => '',
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'evidence' => [$evidence],
            ];
        }

        // Extract referenceBlock (overrides)
        foreach ($xml->xpath('//referenceBlock') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'referenceBlock',
                'name' => (string) ($node['name'] ?? ''),
                'class' => (string) ($node['class'] ?? ''),
                'template' => (string) ($node['template'] ?? ''),
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'remove' => strtolower((string) ($node['remove'] ?? 'false')) === 'true',
                'evidence' => [$evidence],
            ];
        }

        // Extract referenceContainer
        foreach ($xml->xpath('//referenceContainer') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'referenceContainer',
                'name' => (string) ($node['name'] ?? ''),
                'class' => '',
                'template' => '',
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'remove' => strtolower((string) ($node['remove'] ?? 'false')) === 'true',
                'evidence' => [$evidence],
            ];
        }

        // Extract move directives
        foreach ($xml->xpath('//move') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'move',
                'name' => (string) ($node['element'] ?? ''),
                'destination' => (string) ($node['destination'] ?? ''),
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'evidence' => [$evidence],
            ];
        }

        // Extract remove directives
        foreach ($xml->xpath('//remove') ?: [] as $node) {
            $entries[] = [
                'handle' => $handleName,
                'type' => 'remove',
                'name' => (string) ($node['name'] ?? ''),
                'area' => $area,
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'evidence' => [$evidence],
            ];
        }

        return $entries;
    }

    private function buildHandleMap(array $handles): array
    {
        $map = [];
        foreach ($handles as $entry) {
            $handle = $entry['handle'];
            if (!isset($map[$handle])) {
                $map[$handle] = [];
            }
            $map[$handle][] = $entry;
        }
        ksort($map);
        return $map;
    }

    private function countType(array $handles, string $type): int
    {
        return count(array_filter($handles, fn($h) => ($h['type'] ?? '') === $type));
    }

}

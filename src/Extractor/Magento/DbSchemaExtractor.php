<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * DB schema and patches with evidence.
 */
class DbSchemaExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'db_schema_patches';
    }

    public function getDescription(): string
    {
        return 'Extracts declarative schema (db_schema.xml) and discovers data/schema patch classes';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $tables = [];
        $patches = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            // Parse db_schema.xml files
            $schemaFinder = new Finder();
            $schemaFinder->files()
                ->in($scopePath)
                ->name('db_schema.xml')
                ->sortByName();

            foreach ($schemaFinder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseDbSchemaXml($file->getRealPath(), $repoPath, $fileId, $declaringModule);
                foreach ($parsed as $table) {
                    $tables[] = $table;
                }
            }

            // Find data/schema patch PHP files
            $patchFinder = new Finder();
            $patchFinder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('/Setup\/(Patch|Recurring)/')
                ->sortByName();

            foreach ($patchFinder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $patch = $this->parsePatchFile($file->getRealPath(), $repoPath, $fileId, $declaringModule);
                if ($patch !== null) {
                    $patches[] = $patch;
                }
            }
        }

        return [
            'tables' => $tables,
            'patches' => $patches,
            'summary' => [
                'total_tables' => count($tables),
                'total_columns' => array_sum(array_map(fn($t) => count($t['columns'] ?? []), $tables)),
                'total_indexes' => array_sum(array_map(fn($t) => count($t['indexes'] ?? []), $tables)),
                'total_constraints' => array_sum(array_map(fn($t) => count($t['constraints'] ?? []), $tables)),
                'total_patches' => count($patches),
                'patches_by_type' => $this->countPatchesByType($patches),
            ],
        ];
    }

    private function parseDbSchemaXml(string $filePath, string $repoPath, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'db_schema.xml');
            return [];
        }

        $tables = [];

        foreach ($xml->table ?? [] as $tableNode) {
            $tableName = (string) ($tableNode['name'] ?? '');
            if ($tableName === '') {
                continue;
            }

            $table = [
                'name' => $tableName,
                'resource' => (string) ($tableNode['resource'] ?? 'default'),
                'engine' => (string) ($tableNode['engine'] ?? 'innodb'),
                'comment' => (string) ($tableNode['comment'] ?? ''),
                'declared_by' => $declaringModule,
                'source_file' => $fileId,
                'columns' => [],
                'indexes' => [],
                'constraints' => [],
                'evidence' => [
                    Evidence::fromXml($fileId, "table '{$tableName}'")->toArray(),
                ],
            ];

            // Columns
            foreach ($tableNode->column ?? [] as $colNode) {
                $table['columns'][] = [
                    'name' => (string) ($colNode['name'] ?? ''),
                    'type' => (string) ($colNode->attributes('xsi', true)->type ?? ''),
                    'nullable' => strtolower((string) ($colNode['nullable'] ?? 'true')) === 'true',
                    'identity' => strtolower((string) ($colNode['identity'] ?? 'false')) === 'true',
                    'comment' => (string) ($colNode['comment'] ?? ''),
                ];
            }

            // Indexes
            foreach ($tableNode->index ?? [] as $idxNode) {
                $indexColumns = [];
                foreach ($idxNode->column ?? [] as $idxCol) {
                    $indexColumns[] = (string) ($idxCol['name'] ?? '');
                }
                $table['indexes'][] = [
                    'referenceId' => (string) ($idxNode['referenceId'] ?? ''),
                    'indexType' => (string) ($idxNode['indexType'] ?? 'btree'),
                    'columns' => $indexColumns,
                ];
            }

            // Constraints
            foreach ($tableNode->constraint ?? [] as $conNode) {
                $conType = (string) ($conNode->attributes('xsi', true)->type ?? '');
                $constraint = [
                    'referenceId' => (string) ($conNode['referenceId'] ?? ''),
                    'type' => $conType,
                ];

                if ($conType === 'foreign') {
                    $constraint['table'] = (string) ($conNode['table'] ?? '');
                    $constraint['column'] = (string) ($conNode['column'] ?? '');
                    $constraint['referenceTable'] = (string) ($conNode['referenceTable'] ?? '');
                    $constraint['referenceColumn'] = (string) ($conNode['referenceColumn'] ?? '');
                    $constraint['onDelete'] = (string) ($conNode['onDelete'] ?? 'CASCADE');
                }

                $table['constraints'][] = $constraint;
            }

            $tables[] = $table;
        }

        return $tables;
    }

    private function parsePatchFile(string $filePath, string $repoPath, string $fileId, string $declaringModule): ?array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Determine patch type from implemented interfaces or directory path
        $type = 'unknown';
        if (str_contains($content, 'DataPatchInterface') || str_contains($fileId, '/Patch/Data/')) {
            $type = 'data';
        } elseif (str_contains($content, 'SchemaPatchInterface') || str_contains($fileId, '/Patch/Schema/')) {
            $type = 'schema';
        } elseif (str_contains($fileId, '/Recurring')) {
            $type = 'recurring';
        }

        // Extract class name
        $className = '';
        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $content, $nsMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            $className = $nsMatch[1] . '\\' . $classMatch[1];
        }

        // Extract dependencies from getDependencies() if present
        $dependencies = [];
        if (preg_match('/function\s+getDependencies\s*\(\s*\).*?return\s+\[(.*?)\]/s', $content, $depMatch)) {
            preg_match_all('/[\w\\\\]+::class/', $depMatch[1], $depClasses);
            foreach ($depClasses[0] ?? [] as $dep) {
                $dependencies[] = str_replace('::class', '', $dep);
            }
        }

        // Extract aliases from getAliases() if present
        $aliases = [];
        if (preg_match('/function\s+getAliases\s*\(\s*\).*?return\s+\[(.*?)\]/s', $content, $aliasMatch)) {
            preg_match_all("/['\"]([^'\"]+)['\"]/", $aliasMatch[1], $aliasNames);
            $aliases = $aliasNames[1] ?? [];
        }

        return [
            'class' => IdentityResolver::normalizeFqcn($className),
            'type' => $type,
            'module' => $declaringModule,
            'source_file' => $fileId,
            'dependencies' => $dependencies,
            'aliases' => $aliases,
            'evidence' => [
                Evidence::fromPhpAst($fileId, 0, null, "{$type} patch {$className}")->toArray(),
            ],
        ];
    }

    private function countPatchesByType(array $patches): array
    {
        $counts = [];
        foreach ($patches as $p) {
            $type = $p['type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

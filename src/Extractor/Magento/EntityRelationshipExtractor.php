<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Extractor 4 — Entity Relationship + Domain Truth Extractor.
 *
 * Extracts:
 * - Entity classes (Model, ResourceModel, Collection) and their relationships
 * - Database table → entity class mappings from ResourceModel
 * - Foreign key relationships from db_schema.xml
 * - Domain invariants: required fields, status enums, and validation patterns
 * - EAV vs flat table classification
 *
 * AI failure mode prevented:
 * Inserting a row in quote_item without the required quote_id foreign key,
 * or missing a status transition constraint.
 */
class EntityRelationshipExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'entity_relationships';
    }

    public function getDescription(): string
    {
        return 'Maps entity classes to tables, extracts domain relationships, foreign keys, and invariants';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Extract ResourceModel → table mappings
        $resourceModels = $this->discoverResourceModels($repoPath, $scopes, $parser);

        // 2. Extract Collections → ResourceModel mappings
        $collections = $this->discoverCollections($repoPath, $scopes, $parser);

        // 3. Extract foreign key relationships from db_schema.xml
        $foreignKeys = $this->extractForeignKeys($repoPath, $scopes);

        // 4. Build entity map: entity class → table → resource model → collection
        $entityMap = $this->buildEntityMap($resourceModels, $collections);

        // 5. Discover domain invariants from Model classes (status constants, validation)
        $domainInvariants = $this->discoverDomainInvariants($repoPath, $scopes, $parser);

        // 6. Build relationship graph from foreign keys
        $relationships = $this->buildRelationshipGraph($foreignKeys, $resourceModels);

        // 7. Classify EAV vs flat entities
        $entityMap = $this->classifyEntityTypes($entityMap, $repoPath, $scopes);

        // Sort for determinism
        usort($entityMap, fn($a, $b) => strcmp($a['entity_class'] ?? '', $b['entity_class'] ?? ''));
        usort($relationships, fn($a, $b) => strcmp($a['from_table'], $b['from_table']));
        usort($domainInvariants, fn($a, $b) => strcmp($a['class'], $b['class']));

        return [
            'entities' => $entityMap,
            'relationships' => $relationships,
            'foreign_keys' => $foreignKeys,
            'domain_invariants' => $domainInvariants,
            'summary' => [
                'total_entities' => count($entityMap),
                'total_resource_models' => count($resourceModels),
                'total_collections' => count($collections),
                'total_foreign_keys' => count($foreignKeys),
                'total_relationships' => count($relationships),
                'total_domain_invariants' => count($domainInvariants),
                'eav_entities' => count(array_filter($entityMap, fn($e) => ($e['type'] ?? '') === 'eav')),
                'flat_entities' => count(array_filter($entityMap, fn($e) => ($e['type'] ?? '') === 'flat')),
                'by_module' => $this->countByField($entityMap, 'module'),
            ],
        ];
    }

    /**
     * Discover ResourceModel classes and extract table/connection mappings.
     *
     * @return array<array{class: string, table: string, id_field: string, connection: string, module: string, file: string}>
     */
    private function discoverResourceModels(string $repoPath, array $scopes, $parser): array
    {
        $resourceModels = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('#ResourceModel#')
                ->notPath('#Collection#')
                ->notPath('#Collection\.php#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                // Look for _init() call which sets the table name
                $tableMapping = $this->extractResourceModelInit($content);
                if ($tableMapping === null) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());

                // Extract connection name if overridden
                $connection = 'default';
                if (preg_match('/\$_connectionName\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $connMatch)) {
                    $connection = $connMatch[1];
                }

                $resourceModels[] = [
                    'class' => IdentityResolver::normalizeFqcn($className),
                    'class_id' => IdentityResolver::classId($className),
                    'table' => $tableMapping['table'],
                    'id_field' => $tableMapping['id_field'],
                    'connection' => $connection,
                    'module' => $module,
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromPhpAst(
                            $fileId,
                            0,
                            null,
                            "ResourceModel {$className} → table '{$tableMapping['table']}' id='{$tableMapping['id_field']}'"
                        )->toArray(),
                    ],
                ];
            }
        }

        return $resourceModels;
    }

    /**
     * Extract table name and ID field from _init() in a ResourceModel.
     *
     * Looks for patterns like:
     *   $this->_init('table_name', 'id_field')
     *   parent::_construct(); ... _init('table', 'field')
     */
    private function extractResourceModelInit(string $content): ?array
    {
        // Match _init('table_name', 'id_field')
        if (preg_match('/_init\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
            return ['table' => $m[1], 'id_field' => $m[2]];
        }

        // Also match const TABLE_NAME patterns used with _init
        if (preg_match('/const\s+(?:MAIN_TABLE|TABLE_NAME|TABLE)\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $tableConst)) {
            $idField = 'entity_id';
            if (preg_match('/const\s+(?:ID_FIELD_NAME|PRIMARY_KEY)\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $idConst)) {
                $idField = $idConst[1];
            } elseif (preg_match('/_init\s*\(\s*(?:self|static)::(?:MAIN_TABLE|TABLE_NAME|TABLE)\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
                $idField = $m[1];
            }
            return ['table' => $tableConst[1], 'id_field' => $idField];
        }

        return null;
    }

    /**
     * Discover Collection classes and their model/resource model associations.
     */
    private function discoverCollections(string $repoPath, array $scopes, $parser): array
    {
        $collections = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('Collection.php')
                ->path('#ResourceModel#')
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
                $module = $this->resolveModuleFromFile($file->getRealPath());

                // Extract _init(ModelClass, ResourceModelClass)
                $initMapping = $this->extractCollectionInit($content);

                $collections[] = [
                    'class' => IdentityResolver::normalizeFqcn($className),
                    'class_id' => IdentityResolver::classId($className),
                    'model_class' => $initMapping['model'] ?? null,
                    'resource_model_class' => $initMapping['resource_model'] ?? null,
                    'module' => $module,
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromPhpAst($fileId, 0, null, "Collection {$className}")->toArray(),
                    ],
                ];
            }
        }

        return $collections;
    }

    /**
     * Extract model and resource model from Collection _init().
     *
     * Pattern: $this->_init(ModelClass::class, ResourceModelClass::class)
     */
    private function extractCollectionInit(string $content): array
    {
        $result = ['model' => null, 'resource_model' => null];

        // Match _init(Model::class, ResourceModel::class)
        if (preg_match('/_init\s*\(\s*([\\\\A-Za-z0-9_]+)::class\s*,\s*([\\\\A-Za-z0-9_]+)::class\s*\)/', $content, $m)) {
            $result['model'] = $this->resolveClassReference($m[1], $content);
            $result['resource_model'] = $this->resolveClassReference($m[2], $content);
        }
        // Match _init('Full\Class\Name', 'Full\ResourceModel\Name')
        elseif (preg_match('/_init\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
            $result['model'] = IdentityResolver::normalizeFqcn($m[1]);
            $result['resource_model'] = IdentityResolver::normalizeFqcn($m[2]);
        }

        return $result;
    }

    /**
     * Resolve a short class reference (e.g., Model) to FQCN using use statements.
     */
    private function resolveClassReference(string $shortName, string $content): string
    {
        $shortName = ltrim($shortName, '\\');

        // If already FQCN
        if (str_contains($shortName, '\\')) {
            return IdentityResolver::normalizeFqcn($shortName);
        }

        // Find in use statements
        if (preg_match('/use\s+([\\\\A-Za-z0-9_]+\\\\' . preg_quote($shortName, '/') . ')\s*;/', $content, $m)) {
            return IdentityResolver::normalizeFqcn($m[1]);
        }

        // Aliased use
        if (preg_match('/use\s+([\\\\A-Za-z0-9_]+)\s+as\s+' . preg_quote($shortName, '/') . '\s*;/', $content, $m)) {
            return IdentityResolver::normalizeFqcn($m[1]);
        }

        return $shortName;
    }

    /**
     * Extract foreign keys from db_schema.xml.
     */
    private function extractForeignKeys(string $repoPath, array $scopes): array
    {
        $foreignKeys = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('db_schema.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $this->warnInvalidXml($fileId, 'db_schema.xml');
                    continue;
                }

                foreach ($xml->table ?? [] as $tableNode) {
                    $tableName = (string) ($tableNode['name'] ?? '');
                    if ($tableName === '') {
                        continue;
                    }

                    foreach ($tableNode->constraint ?? [] as $conNode) {
                        $conType = (string) ($conNode->attributes('xsi', true)->type ?? '');
                        if ($conType !== 'foreign') {
                            continue;
                        }

                        $column = (string) ($conNode['column'] ?? '');
                        $referenceTable = (string) ($conNode['referenceTable'] ?? '');
                        $referenceColumn = (string) ($conNode['referenceColumn'] ?? '');
                        $onDelete = (string) ($conNode['onDelete'] ?? 'CASCADE');

                        if ($column === '' || $referenceTable === '' || $referenceColumn === '') {
                            continue;
                        }

                        $foreignKeys[] = [
                            'from_table' => $tableName,
                            'from_column' => $column,
                            'to_table' => $referenceTable,
                            'to_column' => $referenceColumn,
                            'on_delete' => $onDelete,
                            'reference_id' => (string) ($conNode['referenceId'] ?? ''),
                            'module' => $module,
                            'evidence' => [
                                Evidence::fromXml(
                                    $fileId,
                                    "FK {$tableName}.{$column} -> {$referenceTable}.{$referenceColumn}"
                                )->toArray(),
                            ],
                        ];
                    }
                }
            }
        }

        return $foreignKeys;
    }

    /**
     * Build the entity map correlating Model → ResourceModel → Collection → Table.
     */
    private function buildEntityMap(array $resourceModels, array $collections): array
    {
        $entities = [];

        // Index resource models by class name
        $rmByClass = [];
        foreach ($resourceModels as $rm) {
            $rmByClass[$rm['class']] = $rm;
        }

        // Index collections by resource model class
        $collByRm = [];
        foreach ($collections as $col) {
            if ($col['resource_model_class'] !== null) {
                $collByRm[$col['resource_model_class']] = $col;
            }
        }

        // Build entities from collections (which reference both model and resource model)
        $processedModels = [];
        foreach ($collections as $col) {
            $modelClass = $col['model_class'];
            $rmClass = $col['resource_model_class'];

            if ($modelClass === null) {
                continue;
            }

            $rm = $rmByClass[$rmClass] ?? null;
            $table = $rm['table'] ?? null;
            $idField = $rm['id_field'] ?? null;

            $processedModels[$modelClass] = true;

            $entities[] = [
                'entity_class' => $modelClass,
                'entity_class_id' => IdentityResolver::classId($modelClass),
                'resource_model' => $rmClass,
                'collection' => $col['class'],
                'table' => $table,
                'id_field' => $idField,
                'connection' => $rm['connection'] ?? 'default',
                'module' => $col['module'],
                'type' => 'flat',
                'evidence' => array_merge(
                    $col['evidence'],
                    $rm !== null ? $rm['evidence'] : []
                ),
            ];
        }

        // Add resource models whose models weren't found via collections
        foreach ($resourceModels as $rm) {
            // Infer model class from resource model path
            $inferredModel = str_replace('\\ResourceModel\\', '\\', $rm['class']);
            if (isset($processedModels[$inferredModel])) {
                continue;
            }

            $col = $collByRm[$rm['class']] ?? null;

            $entities[] = [
                'entity_class' => $inferredModel,
                'entity_class_id' => IdentityResolver::classId($inferredModel),
                'resource_model' => $rm['class'],
                'collection' => $col ? $col['class'] : null,
                'table' => $rm['table'],
                'id_field' => $rm['id_field'],
                'connection' => $rm['connection'],
                'module' => $rm['module'],
                'type' => 'flat',
                'evidence' => $rm['evidence'],
            ];
        }

        return $entities;
    }

    /**
     * Discover domain invariants: status constants, required fields, enum-like values.
     */
    private function discoverDomainInvariants(string $repoPath, array $scopes, $parser): array
    {
        $invariants = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('#Model#')
                ->notPath('#ResourceModel#')
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
                $module = $this->resolveModuleFromFile($file->getRealPath());

                // Extract status-like constants (STATE_*, STATUS_*, TYPE_*)
                $statusConstants = $this->extractStatusConstants($content);

                // Extract validation patterns (beforeSave checks, required fields)
                $validationRules = $this->extractValidationPatterns($content);

                if (empty($statusConstants) && empty($validationRules)) {
                    continue;
                }

                $invariants[] = [
                    'class' => IdentityResolver::normalizeFqcn($className),
                    'class_id' => IdentityResolver::classId($className),
                    'module' => $module,
                    'status_constants' => $statusConstants,
                    'validation_rules' => $validationRules,
                    'source_file' => $fileId,
                    'evidence' => [
                        Evidence::fromPhpAst(
                            $fileId,
                            0,
                            null,
                            "domain invariants for {$className}"
                        )->toArray(),
                    ],
                ];
            }
        }

        return $invariants;
    }

    /**
     * Extract status/state/type constants from a class.
     */
    private function extractStatusConstants(string $content): array
    {
        $constants = [];

        // Match const STATUS_ACTIVE = 1; or const STATE_OPEN = 'open';
        $pattern = '/const\s+((?:STATUS|STATE|TYPE|FLAG)_\w+)\s*=\s*([^;]+);/';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = $m[1];
                $value = trim($m[2]);

                // Resolve value
                if (preg_match('/^[\'"](.+)[\'"]$/', $value, $vMatch)) {
                    $value = $vMatch[1];
                } elseif (is_numeric($value)) {
                    $value = (int) $value;
                }

                $constants[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }

        return $constants;
    }

    /**
     * Extract validation patterns from beforeSave() or validate() methods.
     */
    private function extractValidationPatterns(string $content): array
    {
        $rules = [];

        // Look for required field checks in beforeSave or validate
        if (preg_match_all('/if\s*\(\s*!\s*\$this->getData\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            foreach ($matches[1] as $field) {
                $rules[] = [
                    'type' => 'required_field',
                    'field' => $field,
                    'note' => "Field '{$field}' is checked for presence before save",
                ];
            }
        }

        // Look for hasData checks
        if (preg_match_all('/if\s*\(\s*!\s*\$this->hasData\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $matches)) {
            foreach ($matches[1] as $field) {
                $rules[] = [
                    'type' => 'required_field',
                    'field' => $field,
                    'note' => "Field '{$field}' is required (hasData check)",
                ];
            }
        }

        // Look for status/state validation
        if (preg_match('/function\s+(?:beforeSave|validate)\b/', $content)) {
            if (preg_match_all('/in_array\s*\(\s*\$this->get\w+\(\)\s*,\s*\[([^\]]+)\]/', $content, $matches)) {
                foreach ($matches[1] as $allowedValues) {
                    $rules[] = [
                        'type' => 'enum_constraint',
                        'allowed_values' => $allowedValues,
                        'note' => 'Status/type field is constrained to a set of allowed values',
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Build relationship graph from foreign keys, enriched with entity class info.
     */
    private function buildRelationshipGraph(array $foreignKeys, array $resourceModels): array
    {
        // Build table → entity class lookup
        $tableToEntity = [];
        foreach ($resourceModels as $rm) {
            $tableToEntity[$rm['table']] = [
                'class' => $rm['class'],
                'module' => $rm['module'],
            ];
        }

        $relationships = [];
        foreach ($foreignKeys as $fk) {
            $fromEntity = $tableToEntity[$fk['from_table']] ?? null;
            $toEntity = $tableToEntity[$fk['to_table']] ?? null;

            // Determine relationship type (many-to-one is default for FK)
            $relType = 'many_to_one';

            // Check for one-to-one: FK column is also the primary key
            if ($fromEntity !== null && $fk['from_column'] === ($fromEntity['id_field'] ?? null)) {
                $relType = 'one_to_one';
            }

            $relationships[] = [
                'from_table' => $fk['from_table'],
                'from_column' => $fk['from_column'],
                'to_table' => $fk['to_table'],
                'to_column' => $fk['to_column'],
                'relationship_type' => $relType,
                'on_delete' => $fk['on_delete'],
                'from_entity' => $fromEntity ? $fromEntity['class'] : null,
                'to_entity' => $toEntity ? $toEntity['class'] : null,
                'cross_module' => ($fromEntity && $toEntity)
                    ? $fromEntity['module'] !== $toEntity['module']
                    : false,
                'note' => $this->describeRelationship($fk, $fromEntity, $toEntity),
                'evidence' => $fk['evidence'],
            ];
        }

        return $relationships;
    }

    /**
     * Classify entities as EAV or flat based on directory structure and parent classes.
     */
    private function classifyEntityTypes(array $entityMap, string $repoPath, array $scopes): array
    {
        foreach ($entityMap as &$entity) {
            $rmClass = $entity['resource_model'] ?? '';

            // Check if resource model extends AbstractEav or similar
            if ($rmClass !== '') {
                $filePath = $this->context !== null
                    ? $this->moduleResolver()->resolveClassFile($rmClass)
                    : null;

                if ($filePath !== null && is_file($filePath)) {
                    $content = @file_get_contents($filePath);
                    if ($content !== false) {
                        if (str_contains($content, 'extends AbstractEntity')
                            || str_contains($content, 'Eav\\')
                            || str_contains($content, '_type\' =>')) {
                            $entity['type'] = 'eav';
                        }
                    }
                }
            }

            // Heuristic: if table name ends with _entity or has _eav_ or is known EAV table
            $table = $entity['table'] ?? '';
            if (str_contains($table, '_entity') || str_contains($table, '_eav_')) {
                $entity['type'] = 'eav';
            }
        }
        unset($entity);

        return $entityMap;
    }

    /**
     * Generate a human-readable description of a relationship.
     */
    private function describeRelationship(array $fk, ?array $fromEntity, ?array $toEntity): string
    {
        $from = $fromEntity ? $fromEntity['class'] : $fk['from_table'];
        $to = $toEntity ? $toEntity['class'] : $fk['to_table'];

        $desc = "{$from} references {$to} via {$fk['from_column']} → {$fk['to_column']}";
        if ($fk['on_delete'] === 'CASCADE') {
            $desc .= ' (CASCADE delete — child rows are removed when parent is deleted)';
        } elseif ($fk['on_delete'] === 'SET NULL') {
            $desc .= ' (SET NULL on delete — field becomes NULL when parent is deleted)';
        } elseif ($fk['on_delete'] === 'NO ACTION') {
            $desc .= ' (NO ACTION — parent cannot be deleted while children exist)';
        }

        return $desc;
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
}

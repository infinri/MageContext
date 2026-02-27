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
 * Extractor 7 — DTO / Data Interface Extractor.
 *
 * Extracts:
 * - All Api/Data/*Interface getter and setter signatures
 * - What data is actually available on domain objects passed through service contracts
 * - Which fields are nullable, which are always present, and what their types are
 *
 * AI failure mode prevented:
 * Calling non-existent getters on domain objects; missing available context data
 * that would improve validation logic.
 */
class DtoDataInterfaceExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'dto_data_interfaces';
    }

    public function getDescription(): string
    {
        return 'Extracts Api/Data/*Interface getter/setter signatures with types and nullability';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $dtos = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*Interface.php')
                ->path('#Api/Data#')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $module = $this->resolveModuleFromFile($file->getRealPath());
                $content = @file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $dto = $this->parseDataInterface($content, $parser, $fileId, $module);
                if ($dto !== null) {
                    $dtos[] = $dto;
                }
            }
        }

        usort($dtos, fn($a, $b) => strcmp($a['interface'], $b['interface']));

        // Build field inventory: all unique field names across all DTOs
        $fieldInventory = $this->buildFieldInventory($dtos);

        $totalGetters = array_sum(array_map(fn($d) => count($d['getters']), $dtos));
        $totalSetters = array_sum(array_map(fn($d) => count($d['setters']), $dtos));
        $totalConstants = array_sum(array_map(fn($d) => count($d['constants']), $dtos));

        return [
            'data_interfaces' => $dtos,
            'field_inventory' => $fieldInventory,
            'summary' => [
                'total_data_interfaces' => count($dtos),
                'total_getters' => $totalGetters,
                'total_setters' => $totalSetters,
                'total_constants' => $totalConstants,
                'by_module' => $this->countByField($dtos, 'module'),
            ],
        ];
    }

    /**
     * Parse an Api/Data/*Interface file to extract getters, setters, constants.
     */
    private function parseDataInterface(string $content, $parser, string $fileId, string $module): ?array
    {
        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $stmts = $parser->parse($content);
            if ($stmts === null) {
                return null;
            }
            $stmts = $traverser->traverse($stmts);
        } catch (\Throwable) {
            $this->warnGeneral("Failed to parse PHP: {$fileId}");
            return null;
        }

        $interfaceNode = $this->findInterface($stmts);
        if ($interfaceNode === null) {
            return null;
        }

        $fqcn = $interfaceNode->namespacedName
            ? $interfaceNode->namespacedName->toString()
            : ($interfaceNode->name ? $interfaceNode->name->toString() : '');
        if ($fqcn === '') {
            return null;
        }

        $classId = IdentityResolver::classId($fqcn);

        // Extract extends
        $extends = [];
        foreach ($interfaceNode->extends as $ext) {
            $extends[] = IdentityResolver::normalizeFqcn($ext->toString());
        }

        // Extract constants (field name constants like KEY_NAME = 'name')
        $constants = [];
        foreach ($interfaceNode->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $constName = $const->name->toString();
                    $constValue = $this->resolveConstValue($const->value);
                    $constants[] = [
                        'name' => $constName,
                        'value' => $constValue,
                    ];
                }
            }
        }

        // Extract methods, classifying as getter or setter
        $getters = [];
        $setters = [];
        $otherMethods = [];

        foreach ($interfaceNode->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $methodName = $stmt->name->toString();
            $returnType = $this->resolveType($stmt->returnType);
            $docblock = $this->extractDocblock($stmt);
            $docMeta = $this->parseDocblockAnnotations($docblock);

            // Determine effective return type (prefer native, fallback to docblock)
            $effectiveReturn = $returnType;
            if ($effectiveReturn === 'mixed' && $docMeta['return'] !== '') {
                $effectiveReturn = $docMeta['return'];
            }

            $isNullable = str_starts_with($returnType, '?')
                || str_contains($returnType, '|null')
                || str_contains($docMeta['return'], 'null');

            if ($this->isGetter($methodName)) {
                $fieldName = $this->getterToFieldName($methodName);
                $getters[] = [
                    'method' => $methodName,
                    'field' => $fieldName,
                    'return_type' => $effectiveReturn,
                    'nullable' => $isNullable,
                    'line' => $stmt->getLine(),
                    'docblock' => $docblock,
                    'evidence' => [
                        Evidence::fromPhpAst(
                            $fileId,
                            $stmt->getLine(),
                            $stmt->getEndLine(),
                            "getter {$fqcn}::{$methodName}"
                        )->toArray(),
                    ],
                ];
            } elseif ($this->isSetter($methodName)) {
                $fieldName = $this->setterToFieldName($methodName);
                $params = [];
                foreach ($stmt->params as $param) {
                    $params[] = [
                        'name' => '$' . $param->var->name,
                        'type' => $this->resolveType($param->type),
                        'nullable' => $param->type instanceof Node\NullableType,
                    ];
                }
                $setters[] = [
                    'method' => $methodName,
                    'field' => $fieldName,
                    'parameters' => $params,
                    'return_type' => $effectiveReturn,
                    'line' => $stmt->getLine(),
                    'evidence' => [
                        Evidence::fromPhpAst(
                            $fileId,
                            $stmt->getLine(),
                            $stmt->getEndLine(),
                            "setter {$fqcn}::{$methodName}"
                        )->toArray(),
                    ],
                ];
            } else {
                $otherMethods[] = [
                    'method' => $methodName,
                    'return_type' => $effectiveReturn,
                    'line' => $stmt->getLine(),
                ];
            }
        }

        if (empty($getters) && empty($setters) && empty($constants)) {
            return null;
        }

        // Build field map: correlate getters and setters by field name
        $fields = $this->buildFieldMap($getters, $setters, $constants);

        return [
            'interface' => IdentityResolver::normalizeFqcn($fqcn),
            'class_id' => $classId,
            'module' => $module,
            'extends' => $extends,
            'constants' => $constants,
            'getters' => $getters,
            'setters' => $setters,
            'other_methods' => $otherMethods,
            'fields' => $fields,
            'source_file' => $fileId,
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    $interfaceNode->getLine(),
                    $interfaceNode->getEndLine(),
                    "data interface {$fqcn}"
                )->toArray(),
            ],
        ];
    }

    /**
     * Build a unified field map from getters, setters, and constants.
     *
     * @return array<array{field: string, type: string, nullable: bool, has_getter: bool, has_setter: bool, constant: ?string}>
     */
    private function buildFieldMap(array $getters, array $setters, array $constants): array
    {
        $fields = [];
        $constantMap = [];
        foreach ($constants as $c) {
            // Map constant value (e.g., 'name') → constant name (e.g., 'KEY_NAME')
            if (is_string($c['value']) && $c['value'] !== '') {
                $constantMap[$c['value']] = $c['name'];
            }
        }

        // Index setters by field
        $setterByField = [];
        foreach ($setters as $s) {
            $setterByField[$s['field']] = $s;
        }

        // Start from getters (primary source of truth for field surface)
        foreach ($getters as $g) {
            $field = $g['field'];
            $hasSetter = isset($setterByField[$field]);
            $constant = $constantMap[$field] ?? null;

            // Determine nullability from setter param if getter doesn't declare it
            $nullable = $g['nullable'];
            if (!$nullable && $hasSetter && !empty($setterByField[$field]['parameters'])) {
                $nullable = $setterByField[$field]['parameters'][0]['nullable'] ?? false;
            }

            $fields[] = [
                'field' => $field,
                'type' => $g['return_type'],
                'nullable' => $nullable,
                'has_getter' => true,
                'has_setter' => $hasSetter,
                'getter_method' => $g['method'],
                'setter_method' => $hasSetter ? $setterByField[$field]['method'] : null,
                'constant' => $constant,
            ];

            unset($setterByField[$field]);
        }

        // Remaining setters without matching getters
        foreach ($setterByField as $field => $s) {
            $type = 'mixed';
            if (!empty($s['parameters'])) {
                $type = $s['parameters'][0]['type'];
            }
            $fields[] = [
                'field' => $field,
                'type' => $type,
                'nullable' => !empty($s['parameters']) && ($s['parameters'][0]['nullable'] ?? false),
                'has_getter' => false,
                'has_setter' => true,
                'getter_method' => null,
                'setter_method' => $s['method'],
                'constant' => $constantMap[$field] ?? null,
            ];
        }

        usort($fields, fn($a, $b) => strcmp($a['field'], $b['field']));
        return $fields;
    }

    /**
     * Build a cross-DTO field inventory.
     */
    private function buildFieldInventory(array $dtos): array
    {
        $inventory = [];
        foreach ($dtos as $dto) {
            foreach ($dto['fields'] as $field) {
                $key = $field['field'];
                if (!isset($inventory[$key])) {
                    $inventory[$key] = [
                        'field' => $key,
                        'used_in' => [],
                        'types' => [],
                    ];
                }
                $inventory[$key]['used_in'][] = $dto['interface'];
                if (!in_array($field['type'], $inventory[$key]['types'], true)) {
                    $inventory[$key]['types'][] = $field['type'];
                }
            }
        }

        // Sort and return only fields appearing in multiple interfaces
        $multi = array_filter($inventory, fn($v) => count($v['used_in']) > 1);
        usort($multi, fn($a, $b) => count($b['used_in']) <=> count($a['used_in']));
        return array_values($multi);
    }

    private function isGetter(string $method): bool
    {
        return str_starts_with($method, 'get') || str_starts_with($method, 'is') || str_starts_with($method, 'has');
    }

    private function isSetter(string $method): bool
    {
        return str_starts_with($method, 'set');
    }

    private function getterToFieldName(string $method): string
    {
        if (str_starts_with($method, 'get')) {
            return $this->toSnakeCase(substr($method, 3));
        }
        if (str_starts_with($method, 'is')) {
            return $this->toSnakeCase(substr($method, 2));
        }
        if (str_starts_with($method, 'has')) {
            return $this->toSnakeCase(substr($method, 3));
        }
        return $this->toSnakeCase($method);
    }

    private function setterToFieldName(string $method): string
    {
        if (str_starts_with($method, 'set')) {
            return $this->toSnakeCase(substr($method, 3));
        }
        return $this->toSnakeCase($method);
    }

    private function toSnakeCase(string $str): string
    {
        if ($str === '') {
            return '';
        }
        return strtolower(ltrim(preg_replace('/([A-Z])/', '_$1', $str), '_'));
    }

    private function findInterface(array $stmts): ?Node\Stmt\Interface_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Interface_) {
                return $stmt;
            }
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Interface_) {
                        return $inner;
                    }
                }
            }
        }
        return null;
    }

    private function resolveType($type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof Node\Name) {
            return IdentityResolver::normalizeFqcn($type->toString());
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->resolveType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->resolveType($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->resolveType($t), $type->types));
        }
        return 'mixed';
    }

    private function extractDocblock(Node $node): string
    {
        $doc = $node->getDocComment();
        return $doc !== null ? $doc->getText() : '';
    }

    private function parseDocblockAnnotations(string $docblock): array
    {
        $result = ['params' => [], 'return' => '', 'throws' => []];
        if ($docblock === '') {
            return $result;
        }

        if (preg_match('/@return\s+(\S+)/m', $docblock, $m)) {
            $result['return'] = $m[1];
        }

        return $result;
    }

    private function resolveConstValue(Node\Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\Float_) {
            return $expr->value;
        }
        return null;
    }
}

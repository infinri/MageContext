<?php

declare(strict_types=1);

namespace MageContext\Extractor\Universal;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\IdentityResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * C.1: Symbol index — enables class→file→module joins without scanning all extractor output.
 *
 * Produces a flat index of every PHP symbol (class, interface, trait, enum) with:
 * - class_id (canonical FQCN)
 * - file_id (relative path)
 * - module_id (owning module)
 * - symbol_type (class|interface|trait|enum)
 * - extends (parent FQCN or null)
 * - implements (interface FQCNs)
 * - public_methods (method names — for join targets)
 * - is_abstract (bool)
 * - is_final (bool)
 *
 * Output: indexes/symbol_index.json
 *
 * This is the foundation for reverse indexes (C.3) and scenario rewrites (C.4).
 * AI consumers can answer "where is class X defined?" in O(1) via this index.
 */
class SymbolIndexExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'symbol_index';
    }

    public function getDescription(): string
    {
        return 'Builds symbol index: class→file→module mapping for O(1) lookups';
    }

    public function getOutputView(): string
    {
        return 'indexes';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $symbols = [];
        $fileCount = 0;
        $parseErrors = 0;

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->exclude(['Test', 'tests', 'Fixture', 'fixtures'])
                ->sortByName();

            foreach ($finder as $file) {
                $fileCount++;
                $absolutePath = $file->getRealPath();
                $fileId = $this->fileId($absolutePath, $repoPath);
                $moduleId = $this->resolveModuleFromFile($absolutePath);

                $content = @file_get_contents($absolutePath);
                if ($content === false) {
                    continue;
                }

                try {
                    $stmts = $parser->parse($content);
                    if ($stmts === null) {
                        continue;
                    }
                    $stmts = $traverser->traverse($stmts);
                } catch (\Throwable) {
                    $parseErrors++;
                    $this->warnGeneral("Failed to parse PHP: {$fileId}");
                    continue;
                }

                $this->extractSymbols($stmts, $fileId, $moduleId, $symbols);
            }
        }

        if ($parseErrors > 0) {
            $this->warnGeneral("Symbol index: {$parseErrors} file(s) failed to parse");
        }

        return [
            'symbols' => $symbols,
            'symbol_kinds' => [
                'emitted' => ['class', 'interface', 'trait', 'enum'],
                'supported' => ['class', 'interface', 'trait', 'enum'],
                'not_indexed' => ['function', 'constant'],
                'note' => 'All PHP symbol types (class/interface/trait/enum) are indexed. Methods are listed per-symbol in public_methods but not indexed as standalone symbols.',
            ],
            'summary' => [
                'total_symbols' => count($symbols),
                'total_files_scanned' => $fileCount,
                'parse_errors' => $parseErrors,
                'by_type' => $this->countByField($symbols, 'symbol_type'),
            ],
        ];
    }

    /**
     * Walk AST nodes and extract symbol declarations.
     */
    private function extractSymbols(array $nodes, string $fileId, string $moduleId, array &$symbols): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            if ($node instanceof Node\Stmt\Namespace_) {
                $this->extractSymbols($node->stmts ?? [], $fileId, $moduleId, $symbols);
                continue;
            }

            if ($node instanceof Node\Stmt\Class_) {
                $this->extractClassSymbol($node, $fileId, $moduleId, $symbols, 'class');
            } elseif ($node instanceof Node\Stmt\Interface_) {
                $this->extractInterfaceSymbol($node, $fileId, $moduleId, $symbols);
            } elseif ($node instanceof Node\Stmt\Trait_) {
                $this->extractTraitSymbol($node, $fileId, $moduleId, $symbols);
            } elseif ($node instanceof Node\Stmt\Enum_) {
                $this->extractEnumSymbol($node, $fileId, $moduleId, $symbols);
            }
        }
    }

    private function extractClassSymbol(Node\Stmt\Class_ $node, string $fileId, string $moduleId, array &$symbols, string $type): void
    {
        if ($node->name === null) {
            return; // anonymous class
        }

        $fqcn = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $extends = null;
        if ($node->extends !== null) {
            $extends = $node->extends->toString();
        }

        $implements = [];
        foreach ($node->implements as $iface) {
            $implements[] = $iface->toString();
        }

        $symbols[] = [
            'class_id' => IdentityResolver::classId($fqcn),
            'fqcn' => $fqcn,
            'symbol_type' => $type,
            'file_id' => $fileId,
            'module_id' => $moduleId,
            'extends' => $extends,
            'implements' => $implements,
            'public_methods' => $this->extractPublicMethods($node),
            'is_abstract' => $node->isAbstract(),
            'is_final' => $node->isFinal(),
            'line' => $node->getLine(),
        ];
    }

    private function extractInterfaceSymbol(Node\Stmt\Interface_ $node, string $fileId, string $moduleId, array &$symbols): void
    {
        if ($node->name === null) {
            return;
        }

        $fqcn = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $extends = [];
        foreach ($node->extends as $parent) {
            $extends[] = $parent->toString();
        }

        $symbols[] = [
            'class_id' => IdentityResolver::classId($fqcn),
            'fqcn' => $fqcn,
            'symbol_type' => 'interface',
            'file_id' => $fileId,
            'module_id' => $moduleId,
            'extends' => count($extends) === 1 ? $extends[0] : (empty($extends) ? null : $extends),
            'implements' => [],
            'public_methods' => $this->extractPublicMethodNames($node),
            'is_abstract' => false,
            'is_final' => false,
            'line' => $node->getLine(),
        ];
    }

    private function extractTraitSymbol(Node\Stmt\Trait_ $node, string $fileId, string $moduleId, array &$symbols): void
    {
        if ($node->name === null) {
            return;
        }

        $fqcn = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $symbols[] = [
            'class_id' => IdentityResolver::classId($fqcn),
            'fqcn' => $fqcn,
            'symbol_type' => 'trait',
            'file_id' => $fileId,
            'module_id' => $moduleId,
            'extends' => null,
            'implements' => [],
            'public_methods' => $this->extractPublicMethodNames($node),
            'is_abstract' => false,
            'is_final' => false,
            'line' => $node->getLine(),
        ];
    }

    private function extractEnumSymbol(Node\Stmt\Enum_ $node, string $fileId, string $moduleId, array &$symbols): void
    {
        if ($node->name === null) {
            return;
        }

        $fqcn = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $implements = [];
        foreach ($node->implements as $iface) {
            $implements[] = $iface->toString();
        }

        $symbols[] = [
            'class_id' => IdentityResolver::classId($fqcn),
            'fqcn' => $fqcn,
            'symbol_type' => 'enum',
            'file_id' => $fileId,
            'module_id' => $moduleId,
            'extends' => null,
            'implements' => $implements,
            'public_methods' => $this->extractPublicMethodNames($node),
            'is_abstract' => false,
            'is_final' => false,
            'line' => $node->getLine(),
        ];
    }

    /**
     * Extract public method names from a class node.
     * Includes visibility-based filtering.
     *
     * @return string[]
     */
    private function extractPublicMethods(Node\Stmt\Class_ $node): array
    {
        $methods = [];
        foreach ($node->getMethods() as $method) {
            if ($method->isPublic()) {
                $methods[] = $method->name->toString();
            }
        }
        sort($methods);
        return $methods;
    }

    /**
     * Extract public method names from interface/trait/enum nodes.
     *
     * @return string[]
     */
    private function extractPublicMethodNames(Node\Stmt\Interface_|Node\Stmt\Trait_|Node\Stmt\Enum_ $node): array
    {
        $methods = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->isPublic()) {
                $methods[] = $stmt->name->toString();
            }
        }
        sort($methods);
        return $methods;
    }

}

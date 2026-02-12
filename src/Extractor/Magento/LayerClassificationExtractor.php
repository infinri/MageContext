<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Layer classification and violation detection with evidence.
 */
class LayerClassificationExtractor extends AbstractExtractor
{
    /**
     * Layer definitions ordered from highest (presentation) to lowest (framework).
     * A higher layer should never depend on a lower layer calling upward.
     * Allowed: Presentation → Service → Domain → Infrastructure
     * Framework is a cross-cutting concern accessible from any layer.
     */
    private const LAYER_ORDER = [
        'presentation' => 0,
        'service' => 1,
        'domain' => 2,
        'infrastructure' => 3,
        'framework' => 4,
    ];

    /**
     * Path patterns for layer classification.
     * Checked in order — first match wins.
     */
    private const LAYER_PATTERNS = [
        // Presentation layer
        'presentation' => [
            '/Controller/',
            '/Block/',
            '/ViewModel/',
            '/view/',
            '/Plugin/',
            '/Ui/',
            '/CustomerData/',
        ],
        // Service layer (API contracts)
        'service' => [
            '/Api/',
            '/Service/',
        ],
        // Domain layer
        'domain' => [
            '/Model/',
            '/ResourceModel/',
            '/Repository/',
            '/Entity/',
            '/Collection/',
        ],
        // Infrastructure layer
        'infrastructure' => [
            '/Setup/',
            '/Cron/',
            '/Queue/',
            '/Console/',
            '/Logger/',
            '/Config/',
            '/Gateway/',
            '/Import/',
            '/Export/',
        ],
        // Framework layer (cross-cutting)
        'framework' => [
            '/Helper/',
            '/Observer/',
            '/registration.php',
        ],
    ];

    /**
     * Rules for illegal dependencies (upward calls).
     * Key = source layer, Value = layers it must NOT depend on.
     * Domain should not call Presentation.
     * Infrastructure should not call Presentation.
     * Service should not call Presentation.
     */
    private const ILLEGAL_DEPS = [
        'domain' => ['presentation'],
        'infrastructure' => ['presentation'],
        'service' => ['presentation'],
    ];

    public function getName(): string
    {
        return 'layer_classification';
    }

    public function getDescription(): string
    {
        return 'Classifies files into architectural layers and detects cross-layer violations';
    }

    public function getOutputView(): string
    {
        return 'module_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Build namespace → module map
        $namespaceMap = $this->buildNamespaceMap($repoPath, $scopes);

        // 2. Classify all PHP files into layers
        $classifications = [];
        $fileLayerMap = []; // relativePath => layer

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fId = $this->fileId($file->getRealPath(), $repoPath);
                $layer = $this->classifyFile($fId);
                $module = $this->resolveModuleFromFile($file->getRealPath());

                $classifications[] = [
                    'file' => $fId,
                    'layer' => $layer,
                    'module' => $module,
                ];

                $fileLayerMap[$fId] = $layer;
            }
        }

        // 3. Detect layer violations by scanning use statements
        $violations = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $fId = $this->fileId($file->getRealPath(), $repoPath);
                $sourceLayer = $fileLayerMap[$fId] ?? 'unknown';

                $illegalTargets = self::ILLEGAL_DEPS[$sourceLayer] ?? [];
                if (empty($illegalTargets)) {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                try {
                    $stmts = $parser->parse($content);
                } catch (\Throwable) {
                    continue;
                }

                if ($stmts === null) {
                    continue;
                }

                $usedClasses = $this->extractUseStatements($stmts);

                foreach ($usedClasses as $usedClass) {
                    $targetFile = $this->classToRelativePath($usedClass);
                    $targetLayer = $this->classifyFile($targetFile);

                    if (in_array($targetLayer, $illegalTargets, true)) {
                        $violations[] = [
                            'from' => $fId,
                            'from_layer' => $sourceLayer,
                            'to' => $usedClass,
                            'to_layer' => $targetLayer,
                            'reason' => ucfirst($sourceLayer) . ' calling ' . ucfirst($targetLayer) . ' layer',
                            'module' => $this->resolveModuleFromFile($file->getRealPath()),
                            'evidence' => [Evidence::fromPhpAst($fId, 0, null, "use {$usedClass} ({$targetLayer} layer)")->toArray()],
                        ];
                    }
                }
            }
        }

        // Summarize
        $byLayer = [];
        foreach ($classifications as $c) {
            $layer = $c['layer'];
            $byLayer[$layer] = ($byLayer[$layer] ?? 0) + 1;
        }
        arsort($byLayer);

        $byModule = [];
        foreach ($violations as $v) {
            $mod = $v['module'];
            $byModule[$mod] = ($byModule[$mod] ?? 0) + 1;
        }
        arsort($byModule);

        return [
            'classifications' => $classifications,
            'violations' => $violations,
            'summary' => [
                'total_files_classified' => count($classifications),
                'total_violations' => count($violations),
                'files_by_layer' => $byLayer,
                'violations_by_module' => $byModule,
            ],
        ];
    }

    private function classifyFile(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);

        foreach (self::LAYER_PATTERNS as $layer => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $layer;
                }
            }
        }

        return 'unknown';
    }

    private function resolveModuleFromPath(string $relativePath): string
    {
        if (preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $relativePath, $match)) {
            return $match[1] . '_' . $match[2];
        }
        return 'unknown';
    }

    /**
     * Convert a FQCN to a relative path pattern for layer classification.
     * e.g., Vendor\Module\Controller\Index => Vendor/Module/Controller/Index.php
     */
    private function classToRelativePath(string $className): string
    {
        return str_replace('\\', '/', ltrim($className, '\\')) . '.php';
    }

    /**
     * Extract all use statement class names from parsed AST.
     *
     * @return array<string>
     */
    private function extractUseStatements(array $stmts): array
    {
        $classes = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $classes[] = $use->name->toString();
                }
            } elseif ($stmt instanceof \PhpParser\Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    $classes[] = $prefix . '\\' . $use->name->toString();
                }
            }
        }

        return $classes;
    }

    /**
     * Build namespace → module map from registration.php and module.xml files.
     *
     * @return array<string, string>
     */
    private function buildNamespaceMap(string $repoPath, array $scopes): array
    {
        $map = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('module.xml')
                ->path('/^[^\/]+\/[^\/]+\/etc\//');

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }
                $moduleNode = $xml->module ?? null;
                if ($moduleNode === null) {
                    continue;
                }
                $name = (string) ($moduleNode['name'] ?? '');
                if ($name !== '') {
                    $namespace = str_replace('_', '\\', $name) . '\\';
                    $map[$namespace] = $name;
                }
            }
        }

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Extractor\Universal;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * C.2: File index — enables file→module→layer lookup in O(1).
 *
 * Produces a flat index of every file in scope with:
 * - file_id (relative path)
 * - module_id (owning module)
 * - layer (presentation/service/domain/infrastructure/framework/unknown)
 * - file_type (php|xml|phtml|js|less|css|html|json|md|other)
 * - size_bytes
 * - directory (parent dir relative to scope)
 *
 * Output: indexes/file_index.json
 *
 * AI consumers can answer "what module owns this file?" or
 * "what layer is this file in?" without scanning other extractors.
 */
class FileIndexExtractor extends AbstractExtractor
{
    /**
     * Path patterns for layer classification (same as LayerClassificationExtractor).
     * Checked in order — first match wins.
     */
    private const LAYER_PATTERNS = [
        'presentation' => [
            '/Controller/',
            '/Block/',
            '/ViewModel/',
            '/view/',
            '/Plugin/',
            '/Ui/',
            '/CustomerData/',
        ],
        'service' => [
            '/Api/',
            '/Service/',
        ],
        'domain' => [
            '/Model/',
            '/ResourceModel/',
            '/Repository/',
            '/Entity/',
            '/Collection/',
        ],
        'infrastructure' => [
            '/Setup/',
            '/Console/',
            '/Cron/',
            '/Observer/',
            '/Helper/',
            '/Logger/',
            '/Gateway/',
        ],
        'framework' => [
            '/etc/',
            '/registration.php',
        ],
    ];

    public function getName(): string
    {
        return 'file_index';
    }

    public function getDescription(): string
    {
        return 'Builds file index: file→module→layer mapping for O(1) lookups';
    }

    public function getOutputView(): string
    {
        return 'indexes';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $files = [];
        $byType = [];
        $byLayer = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->exclude(['Test', 'tests', 'Fixture', 'fixtures', '.git', 'node_modules'])
                ->sortByName();

            foreach ($finder as $file) {
                $absolutePath = $file->getRealPath();
                $fileId = $this->fileId($absolutePath, $repoPath);
                $moduleId = $this->resolveModuleFromFile($absolutePath);
                $extension = strtolower($file->getExtension());
                $fileType = $this->classifyFileType($extension);
                $layer = $this->classifyLayer($fileId);
                $size = $file->getSize();

                $files[] = [
                    'file_id' => $fileId,
                    'module_id' => $moduleId,
                    'layer' => $layer,
                    'file_type' => $fileType,
                    'extension' => $extension,
                    'size_bytes' => $size,
                ];

                $byType[$fileType] = ($byType[$fileType] ?? 0) + 1;
                $byLayer[$layer] = ($byLayer[$layer] ?? 0) + 1;
            }
        }

        ksort($byType);
        ksort($byLayer);

        return [
            'files' => $files,
            'summary' => [
                'total_files' => count($files),
                'by_type' => $byType,
                'by_layer' => $byLayer,
            ],
        ];
    }

    private function classifyFileType(string $extension): string
    {
        return match ($extension) {
            'php' => 'php',
            'xml' => 'xml',
            'phtml' => 'phtml',
            'js' => 'js',
            'less', 'scss', 'sass' => 'less',
            'css' => 'css',
            'html', 'htm' => 'html',
            'json' => 'json',
            'md', 'txt', 'rst' => 'doc',
            'graphqls', 'graphql' => 'graphql',
            'csv' => 'csv',
            'yml', 'yaml' => 'yaml',
            default => 'other',
        };
    }

    private function classifyLayer(string $fileId): string
    {
        foreach (self::LAYER_PATTERNS as $layer => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($fileId, $pattern)) {
                    return $layer;
                }
            }
        }
        return 'unknown';
    }
}

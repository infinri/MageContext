<?php

declare(strict_types=1);

namespace MageContext\Output;

class OutputWriter
{
    private string $outputDir;

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Ensure the output directory structure exists.
     */
    public function prepare(): void
    {
        $dirs = [
            $this->outputDir,
            $this->outputDir . '/magento',
            $this->outputDir . '/knowledge',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Write structured data as JSON.
     */
    public function writeJson(string $relativePath, array $data): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Write structured data as JSONL (one JSON object per line).
     */
    public function writeJsonl(string $relativePath, array $records): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));
        $handle = fopen($path, 'w');

        foreach ($records as $record) {
            fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
        }

        fclose($handle);
    }

    /**
     * Write markdown content.
     */
    public function writeMarkdown(string $relativePath, string $content): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
    }

    /**
     * Write the top-level manifest.json with metadata about the compilation.
     */
    public function writeManifest(array $extractorResults, float $duration): void
    {
        $manifest = [
            'version' => '0.1.0',
            'generated_at' => date('c'),
            'duration_seconds' => round($duration, 2),
            'extractors' => [],
            'files' => [],
        ];

        foreach ($extractorResults as $name => $result) {
            $manifest['extractors'][] = [
                'name' => $name,
                'status' => $result['status'] ?? 'ok',
                'item_count' => $result['item_count'] ?? 0,
            ];
            if (!empty($result['output_files'])) {
                foreach ($result['output_files'] as $file) {
                    $manifest['files'][] = $file;
                }
            }
        }

        $this->writeJson('manifest.json', $manifest);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

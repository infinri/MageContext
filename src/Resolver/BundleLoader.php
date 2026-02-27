<?php

declare(strict_types=1);

namespace MageContext\Resolver;

/**
 * Shared bundle loading logic for resolvers.
 *
 * Reads manifest.json and loads all referenced files into an in-memory index
 * keyed by filename (without extension). Both ContextResolver and GuideResolver
 * delegate to this class to avoid duplicating the load-and-index logic.
 */
class BundleLoader
{
    /**
     * Load compiled context files from a bundle directory.
     *
     * @param string $contextDir Absolute path to the compiled context directory
     * @param bool $includeMarkdown Whether to also load .md files (suffixed with _md key)
     * @return array<string, mixed> Index keyed by filename
     *
     * @throws \RuntimeException If manifest.json is missing
     */
    public static function load(string $contextDir, bool $includeMarkdown = false): array
    {
        $contextDir = rtrim($contextDir, '/');
        $manifestPath = $contextDir . '/manifest.json';

        if (!is_file($manifestPath)) {
            throw new \RuntimeException(
                "No compiled context found at {$contextDir}. Run 'compile' first."
            );
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $index = [];

        foreach ($manifest['files'] ?? [] as $relativePath) {
            $fullPath = $contextDir . '/' . $relativePath;
            if (!is_file($fullPath)) {
                continue;
            }

            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            if ($ext === 'json') {
                $key = pathinfo($relativePath, PATHINFO_FILENAME);
                $index[$key] = json_decode(file_get_contents($fullPath), true);
            } elseif ($includeMarkdown && $ext === 'md') {
                $key = pathinfo($relativePath, PATHINFO_FILENAME) . '_md';
                $index[$key] = file_get_contents($fullPath);
            }
        }

        return $index;
    }

    /**
     * Filter records by matching any term against any field (case-insensitive).
     *
     * @param array<array> $records Array of records to filter
     * @param array<string> $terms Search terms to match
     * @param array<string> $fields Field names to check in each record
     * @return array<array> Matched records
     */
    public static function filterRecords(array $records, array $terms, array $fields): array
    {
        $matched = [];
        foreach ($records as $record) {
            foreach ($fields as $field) {
                $value = $record[$field] ?? '';
                if (!is_string($value)) {
                    continue;
                }
                foreach ($terms as $term) {
                    if (stripos($value, $term) !== false) {
                        $matched[] = $record;
                        continue 3;
                    }
                }
            }
        }
        return $matched;
    }
}

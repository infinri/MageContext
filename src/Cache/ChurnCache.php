<?php

declare(strict_types=1);

namespace MageContext\Cache;

/**
 * Repo-level churn cache.
 *
 * Cache key: HEAD commit + window_days + scopes hash.
 * Stored at {repoPath}/.magecontext-cache/churn.json.
 * Invalidated when HEAD changes or window changes.
 *
 * Stores both per-file churn (for GitChurnExtractor) and
 * per-module churn (for HotspotRankingExtractor) in one file.
 */
class ChurnCache
{
    private string $cacheDir;
    private string $cacheFile;
    private string $repoPath;

    public function __construct(string $repoPath)
    {
        $this->repoPath = rtrim($repoPath, '/');
        $this->cacheDir = $this->repoPath . '/.magecontext-cache';
        $this->cacheFile = $this->cacheDir . '/churn.json';
    }

    /**
     * Try to read cached churn data.
     * Returns null if cache is missing or stale.
     *
     * @param int $windowDays The configured churn window
     * @param string[] $scopes The configured scopes
     * @return array{file_churn: array<string, int>, module_churn: array<string, int>}|null
     */
    public function read(int $windowDays, array $scopes): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false) {
            return null;
        }

        $cached = @json_decode($raw, true);
        if (!is_array($cached)) {
            return null;
        }

        // Validate cache key
        $currentCommit = $this->getHeadCommit();
        $currentScopesHash = $this->scopesHash($scopes);

        if (($cached['commit'] ?? '') !== $currentCommit) {
            return null;
        }
        if (($cached['window_days'] ?? 0) !== $windowDays) {
            return null;
        }
        if (($cached['scopes_hash'] ?? '') !== $currentScopesHash) {
            return null;
        }

        // Cache is valid
        return [
            'file_churn' => $cached['file_churn'] ?? [],
            'module_churn' => $cached['module_churn'] ?? [],
        ];
    }

    /**
     * Write churn data to cache.
     *
     * @param int $windowDays The churn window used
     * @param string[] $scopes The scopes used
     * @param array<string, int> $fileChurn Per-file churn counts
     * @param array<string, int> $moduleChurn Per-module churn counts
     */
    public function write(int $windowDays, array $scopes, array $fileChurn, array $moduleChurn): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }

        $data = [
            'commit' => $this->getHeadCommit(),
            'window_days' => $windowDays,
            'scopes_hash' => $this->scopesHash($scopes),
            'created_at' => date('c'),
            'file_churn' => $fileChurn,
            'module_churn' => $moduleChurn,
        ];

        @file_put_contents(
            $this->cacheFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Write .gitignore for cache dir if not present
        $gitignore = $this->cacheDir . '/.gitignore';
        if (!is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
    }

    /**
     * Get HEAD commit hash via filesystem (no shell dependency).
     */
    public function getHeadCommit(): string
    {
        if (!is_dir($this->repoPath . '/.git')) {
            return 'unknown';
        }

        $headFile = $this->repoPath . '/.git/HEAD';
        if (!is_file($headFile)) {
            return 'unknown';
        }

        $head = trim((string) @file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refPath = $this->repoPath . '/.git/' . substr($head, 5);
            if (is_file($refPath)) {
                return trim((string) @file_get_contents($refPath));
            }
            return 'unknown';
        }

        // Detached HEAD — already a SHA
        return $head !== '' ? $head : 'unknown';
    }

    /**
     * Get the cache directory path.
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    private function scopesHash(array $scopes): string
    {
        sort($scopes);
        return sha1(implode('|', $scopes));
    }
}

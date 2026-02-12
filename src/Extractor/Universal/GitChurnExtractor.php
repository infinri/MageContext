<?php

declare(strict_types=1);

namespace MageContext\Extractor\Universal;

use MageContext\Extractor\AbstractExtractor;

class GitChurnExtractor extends AbstractExtractor
{
    private int $limit;
    private bool $cacheWasUsed = false;

    public function __construct(int $limit = 50)
    {
        $this->limit = $limit;
    }

    public function getName(): string
    {
        return 'git_churn_hotspots';
    }

    public function getDescription(): string
    {
        return 'Identifies files with highest change frequency (churn) weighted by size';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $this->assertGitRepo($repoPath);

        $windowDays = $this->context !== null ? $this->config()->getChurnWindowDays() : 365;
        $cache = $this->context?->getChurnCache();

        // Try cache first
        $cached = $cache?->read($windowDays, $scopes);
        if ($cached !== null && !empty($cached['file_churn'])) {
            $churnData = $cached['file_churn'];
            $this->cacheWasUsed = true;
        } else {
            $churnData = $this->getChurnCounts($repoPath, $scopes, $windowDays);
            $this->cacheWasUsed = false;
        }
        // Phase 1: Compute scores using churn + line count (no per-file git log)
        $candidates = [];

        foreach ($churnData as $file => $changeCount) {
            $absolutePath = $repoPath . '/' . $file;
            if (!is_file($absolutePath)) {
                continue;
            }

            $lineCount = $this->countLines($absolutePath);
            $score = $changeCount * log1p($lineCount);

            $candidates[] = [
                'file' => $file,
                'change_count' => $changeCount,
                'line_count' => $lineCount,
                'score' => round($score, 2),
            ];
        }

        usort($candidates, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $this->limit);

        // Phase 2: Enrich only top N with last_modified (expensive per-file git log)
        $hotspots = [];
        foreach ($candidates as $c) {
            $c['last_modified'] = $this->getLastModified($repoPath, $c['file']);
            $hotspots[] = $c;
        }

        return [
            'hotspots' => $hotspots,
            'total_files_analyzed' => count($churnData),
            'churn_window_days' => $windowDays,
        ];
    }

    /**
     * Get per-file churn data (public for cache writing by CompileCommand).
     *
     * @return array<string, int>
     */
    public function getFileChurn(): array
    {
        return $this->lastFileChurn;
    }

    public function wasCacheUsed(): bool
    {
        return $this->cacheWasUsed;
    }

    /** @var array<string, int> */
    private array $lastFileChurn = [];

    private function getChurnCounts(string $repoPath, array $scopes, int $windowDays): array
    {
        $scopePaths = [];
        foreach ($scopes as $scope) {
            $fullPath = $repoPath . '/' . trim($scope, '/');
            if (is_dir($fullPath)) {
                $scopePaths[] = trim($scope, '/');
            }
        }

        // If no valid scope directories exist, scan the whole repo
        $pathArgs = empty($scopePaths) ? '' : '-- ' . implode(' ', array_map('escapeshellarg', $scopePaths));

        $sinceArg = escapeshellarg("--since={$windowDays} days ago");

        $cmd = sprintf(
            'cd %s && git log --name-only --pretty=format: --diff-filter=AMRC %s %s 2>/dev/null',
            escapeshellarg($repoPath),
            $sinceArg,
            $pathArgs
        );

        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            return [];
        }

        $counts = [];
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!isset($counts[$line])) {
                $counts[$line] = 0;
            }
            $counts[$line]++;
        }

        arsort($counts);
        $this->lastFileChurn = $counts;
        return $counts;
    }

    private function countLines(string $filePath): int
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }
        return substr_count($content, "\n") + 1;
    }

    private function getLastModified(string $repoPath, string $file): string
    {
        $cmd = sprintf(
            'cd %s && git log -1 --format=%%aI -- %s 2>/dev/null',
            escapeshellarg($repoPath),
            escapeshellarg($file)
        );

        $result = trim((string) shell_exec($cmd));
        return $result !== '' ? $result : date('c', filemtime($repoPath . '/' . $file));
    }

    private function assertGitRepo(string $repoPath): void
    {
        if (!is_dir($repoPath . '/.git')) {
            throw new \RuntimeException("Not a git repository: $repoPath");
        }
    }
}

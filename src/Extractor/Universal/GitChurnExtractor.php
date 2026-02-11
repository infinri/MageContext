<?php

declare(strict_types=1);

namespace MageContext\Extractor\Universal;

use MageContext\Extractor\ExtractorInterface;

class GitChurnExtractor implements ExtractorInterface
{
    private int $limit;

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

    public function extract(string $repoPath, array $scopes): array
    {
        $this->assertGitRepo($repoPath);

        $churnData = $this->getChurnCounts($repoPath, $scopes);
        $hotspots = [];

        foreach ($churnData as $file => $changeCount) {
            $absolutePath = $repoPath . '/' . $file;
            if (!is_file($absolutePath)) {
                continue;
            }

            $lineCount = $this->countLines($absolutePath);
            $lastModified = $this->getLastModified($repoPath, $file);

            $score = $changeCount * log1p($lineCount);

            $hotspots[] = [
                'file' => $file,
                'change_count' => $changeCount,
                'line_count' => $lineCount,
                'last_modified' => $lastModified,
                'score' => round($score, 2),
            ];
        }

        usort($hotspots, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        $hotspots = array_slice($hotspots, 0, $this->limit);

        return [
            'hotspots' => $hotspots,
            'total_files_analyzed' => count($churnData),
        ];
    }

    /**
     * Get commit count per file using git log.
     *
     * @return array<string, int> file => commit count
     */
    private function getChurnCounts(string $repoPath, array $scopes): array
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

        $cmd = sprintf(
            'cd %s && git log --name-only --pretty=format: --diff-filter=AMRC %s 2>/dev/null',
            escapeshellarg($repoPath),
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

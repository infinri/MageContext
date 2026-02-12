<?php

declare(strict_types=1);

namespace MageContext\Extractor\Universal;

use MageContext\Extractor\AbstractExtractor;
use Symfony\Component\Finder\Finder;

class RepoMapExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'repo_map';
    }

    public function getDescription(): string
    {
        return 'Generates a structural map of the repository directory tree';
    }

    public function getOutputView(): string
    {
        return '.';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $entries = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->ignoreDotFiles(true)
                ->ignoreVCS(true)
                ->sortByName();

            foreach ($finder as $file) {
                $relativePath = trim($scope, '/') . '/' . $file->getRelativePathname();
                $entries[] = [
                    'path' => $relativePath,
                    'extension' => $file->getExtension(),
                    'size' => $file->getSize(),
                    'type' => $this->classifyFile($file->getExtension(), $relativePath),
                ];
            }
        }

        $summary = $this->buildSummary($entries);

        return [
            'files' => $entries,
            'summary' => $summary,
        ];
    }

    private function classifyFile(string $extension, string $path): string
    {
        if ($extension === 'xml') {
            if (str_contains($path, '/etc/')) {
                return 'config';
            }
            if (str_contains($path, '/layout/')) {
                return 'layout';
            }
            if (str_contains($path, '/ui_component/')) {
                return 'ui_component';
            }
            return 'xml';
        }

        return match ($extension) {
            'php' => 'php',
            'phtml' => 'template',
            'js' => 'javascript',
            'less', 'css', 'scss' => 'style',
            'html', 'ko' => 'knockout_template',
            'json' => 'json',
            'md' => 'documentation',
            default => 'other',
        };
    }

    private function buildSummary(array $entries): array
    {
        $byType = [];
        $byExtension = [];
        $totalSize = 0;

        foreach ($entries as $entry) {
            $type = $entry['type'];
            $ext = $entry['extension'];

            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byExtension[$ext] = ($byExtension[$ext] ?? 0) + 1;
            $totalSize += $entry['size'];
        }

        arsort($byType);
        arsort($byExtension);

        return [
            'total_files' => count($entries),
            'total_size_bytes' => $totalSize,
            'by_type' => $byType,
            'by_extension' => $byExtension,
        ];
    }
}

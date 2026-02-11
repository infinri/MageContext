<?php

declare(strict_types=1);

namespace MageContext\Extractor;

/**
 * All extractors implement this interface.
 * Each extractor is responsible for one concern (DI, plugins, observers, etc.)
 * and outputs structured data that the OutputWriter serializes.
 */
interface ExtractorInterface
{
    /**
     * Unique identifier for this extractor (e.g., 'module_graph', 'di_preferences').
     */
    public function getName(): string;

    /**
     * Human-readable description shown in CLI output.
     */
    public function getDescription(): string;

    /**
     * Run the extraction against the given repo path.
     *
     * @param string $repoPath Absolute path to the repository root.
     * @param array<string> $scopes Directories to scan (e.g., ['app/code', 'app/design']).
     * @return array<string, mixed> Extracted data, keyed by logical section.
     */
    public function extract(string $repoPath, array $scopes): array;
}

<?php

declare(strict_types=1);

namespace MageContext\Target;

use MageContext\Extractor\ExtractorInterface;

interface TargetInterface
{
    /**
     * Unique identifier for this target (e.g., 'magento', 'laravel', 'generic').
     */
    public function getName(): string;

    /**
     * Human-readable description.
     */
    public function getDescription(): string;

    /**
     * Default scopes (directories to scan) for this target.
     *
     * @return array<string>
     */
    public function getDefaultScopes(): array;

    /**
     * Detect whether a given repository root belongs to this target.
     */
    public function detect(string $repoPath): bool;

    /**
     * Return all target-specific extractors.
     *
     * @return array<ExtractorInterface>
     */
    public function getExtractors(): array;

}

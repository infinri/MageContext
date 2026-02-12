<?php

declare(strict_types=1);

namespace MageContext\Target;

class GenericTarget implements TargetInterface
{
    public function getName(): string
    {
        return 'generic';
    }

    public function getDescription(): string
    {
        return 'Generic PHP/any project (universal analyzers only)';
    }

    public function getDefaultScopes(): array
    {
        return ['src', 'app', 'lib'];
    }

    public function detect(string $repoPath): bool
    {
        // Fallback — always matches if nothing else does
        return true;
    }

    public function getExtractors(): array
    {
        // No target-specific extractors; only universal analyzers will run
        return [];
    }

}

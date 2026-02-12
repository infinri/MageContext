<?php

declare(strict_types=1);

namespace MageContext\Extractor;

use MageContext\Cache\ChurnCache;
use MageContext\Config\CompilerConfig;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;

/**
 * Carries foundation services available to all extractors during compilation.
 *
 * This is the single entry point for extractors to access:
 * - ModuleResolver (class→module mapping)
 * - CompilerConfig (thresholds, edge types, etc.)
 * - WarningCollector (typed warnings → integrity scoring)
 * - Repo metadata (commit SHA, repo path)
 *
 * Extractors receive this via setContext() before extract() is called.
 */
class CompilationContext
{
    private string $repoPath;
    private array $scopes;
    private ModuleResolver $moduleResolver;
    private CompilerConfig $config;
    private string $repoCommit;
    private readonly WarningCollector $warnings;
    private ?ChurnCache $churnCache = null;

    public function __construct(
        string $repoPath,
        array $scopes,
        ModuleResolver $moduleResolver,
        CompilerConfig $config,
        string $repoCommit = 'unknown',
        ?WarningCollector $warnings = null
    ) {
        $this->repoPath = $repoPath;
        $this->scopes = $scopes;
        $this->moduleResolver = $moduleResolver;
        $this->config = $config;
        $this->repoCommit = $repoCommit;
        $this->warnings = $warnings ?? new WarningCollector();

        // Initialize churn cache if enabled
        if ($config->isChurnCacheEnabled()) {
            $this->churnCache = new ChurnCache($repoPath);
        }
    }

    public function getRepoPath(): string
    {
        return $this->repoPath;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getModuleResolver(): ModuleResolver
    {
        return $this->moduleResolver;
    }

    public function getConfig(): CompilerConfig
    {
        return $this->config;
    }

    public function getRepoCommit(): string
    {
        return $this->repoCommit;
    }

    /**
     * Get the WarningCollector.
     * This is the ONLY way to emit warnings. No string[] fallback.
     */
    public function getWarnings(): WarningCollector
    {
        return $this->warnings;
    }

    /**
     * Add a typed warning. Convenience delegate to WarningCollector.
     */
    public function addWarning(string $category, string $message, string $extractor = ''): void
    {
        $this->warnings->add($category, $message, $extractor);
    }

    /**
     * Get warnings for a specific extractor and clear them from the collector.
     *
     * @return array<array{category: string, message: string}>
     */
    public function drainWarningsForExtractor(string $extractor): array
    {
        return $this->warnings->drainForExtractor($extractor);
    }

    /**
     * Get ChurnCache instance (null if caching is disabled).
     */
    public function getChurnCache(): ?ChurnCache
    {
        return $this->churnCache;
    }
}

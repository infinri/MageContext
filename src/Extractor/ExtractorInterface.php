<?php

declare(strict_types=1);

namespace MageContext\Extractor;

/**
 * All extractors implement this interface.
 * Each extractor is responsible for one concern (DI, plugins, observers, etc.)
 * and outputs structured data that the OutputWriter serializes.
 *
 * Spec §5.1: "Extractors must be single-purpose."
 * Spec §5.2: "Extractors must emit warnings."
 * Spec §5.3: "Same repo commit + same scope => identical output ordering."
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
     * The architectural view directory this extractor outputs to.
     *
     * One of: 'module_view', 'runtime_view', 'allocation_view', 'quality_metrics', or '.' for top-level.
     */
    public function getOutputView(): string;

    /**
     * Inject the compilation context (foundation services).
     * Called before extract(). Extractors that need ModuleResolver,
     * CompilerConfig, or warning emission should store this reference.
     */
    public function setContext(CompilationContext $context): void;

    /**
     * Run the extraction against the given repo path.
     *
     * @param string $repoPath Absolute path to the repository root.
     * @param array<string> $scopes Directories to scan (e.g., ['app/code', 'app/design']).
     * @return array<string, mixed> Extracted data, keyed by logical section.
     */
    public function extract(string $repoPath, array $scopes): array;
}

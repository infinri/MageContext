<?php

declare(strict_types=1);

namespace MageContext\Extractor;

use MageContext\Config\CompilerConfig;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;

/**
 * Base class for all extractors providing foundation service access.
 *
 * Extractors extending this class get:
 * - CompilationContext injection (ModuleResolver, CompilerConfig, etc.)
 * - Convenience methods for Evidence creation
 * - Warning emission
 * - Module resolution via shared ModuleResolver (eliminates ad-hoc resolveModuleFromClass)
 */
abstract class AbstractExtractor implements ExtractorInterface
{
    protected ?CompilationContext $context = null;

    public function setContext(CompilationContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the ModuleResolver from context.
     */
    protected function moduleResolver(): ModuleResolver
    {
        if ($this->context === null) {
            throw new \RuntimeException(static::class . ': CompilationContext not set. Call setContext() first.');
        }
        return $this->context->getModuleResolver();
    }

    /**
     * Get the CompilerConfig from context.
     */
    protected function config(): CompilerConfig
    {
        if ($this->context === null) {
            throw new \RuntimeException(static::class . ': CompilationContext not set. Call setContext() first.');
        }
        return $this->context->getConfig();
    }

    /**
     * Resolve a FQCN to a module_id using the shared ModuleResolver.
     * Replaces all ad-hoc resolveModuleFromClass() methods.
     */
    protected function resolveModule(string $fqcn): string
    {
        if ($this->context !== null) {
            return $this->context->getModuleResolver()->resolveClass($fqcn);
        }
        // Fallback for extractors not yet migrated
        return IdentityResolver::moduleIdFromClass($fqcn);
    }

    /**
     * Resolve an absolute file path to a module_id.
     */
    protected function resolveModuleFromFile(string $absolutePath): string
    {
        if ($this->context !== null) {
            return $this->context->getModuleResolver()->resolveAbsolutePath($absolutePath);
        }
        return 'unknown';
    }

    /**
     * Emit a typed warning. Category determines integrity score impact.
     * Spec §5.2: "Never silently drop."
     */
    protected function warn(string $category, string $message): void
    {
        if ($this->context !== null) {
            $this->context->addWarning($category, $message, $this->getName());
        }
    }

    protected function warnUnresolvedClass(string $fqcn, string $context = ''): void
    {
        $msg = "Unresolved class: {$fqcn}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_UNRESOLVED_CLASS, $msg);
    }

    protected function warnAmbiguousDi(string $target, string $context = ''): void
    {
        $msg = "Ambiguous DI resolution: {$target}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_AMBIGUOUS_DI, $msg);
    }

    protected function warnInvalidXml(string $file, string $context = ''): void
    {
        $msg = "Invalid XML: {$file}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_INVALID_XML, $msg);
    }

    protected function warnMissingModule(string $module, string $context = ''): void
    {
        $msg = "Missing module: {$module}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_MISSING_MODULE, $msg);
    }

    protected function warnUnresolvedFile(string $file, string $context = ''): void
    {
        $msg = "Unresolved file: {$file}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_UNRESOLVED_FILE, $msg);
    }

    protected function warnGeneral(string $message): void
    {
        $this->warn(WarningCollector::CAT_GENERAL, $message);
    }

    /**
     * Get the repo commit SHA.
     */
    protected function repoCommit(): string
    {
        return $this->context?->getRepoCommit() ?? 'unknown';
    }

    /**
     * Generate a file_id from an absolute path.
     */
    protected function fileId(string $absolutePath, string $repoPath): string
    {
        return IdentityResolver::fileId($absolutePath, $repoPath);
    }

    /**
     * Generate a class_id (normalized FQCN).
     */
    protected function classId(string $fqcn): string
    {
        return IdentityResolver::classId($fqcn);
    }

    /**
     * Generate a method_id.
     */
    protected function methodId(string $fqcn, string $method): string
    {
        return IdentityResolver::methodId($fqcn, $method);
    }

    /**
     * Get the current analysis integrity score from WarningCollector.
     * Returns 1.0 if no context (safe default — no dampening).
     */
    protected function getIntegrityScore(): float
    {
        if ($this->context === null) {
            return 1.0;
        }
        return $this->context->getWarnings()->getIntegrityScore();
    }

    /**
     * Whether the analysis is degraded (integrity_score < 1.0).
     */
    protected function isDegraded(): bool
    {
        return $this->getIntegrityScore() < 1.0;
    }

    /**
     * Apply metric dampening: final_score = raw_score × integrity_score.
     * Returns array with raw_score, final_score, integrity_score_used.
     *
     * Dampening scales magnitude, not ordering. Module rankings remain identical.
     */
    protected function dampenScore(float $rawScore): array
    {
        $integrity = $this->getIntegrityScore();
        return [
            'raw_score' => round($rawScore, 4),
            'final_score' => round($rawScore * $integrity, 4),
            'integrity_score_used' => $integrity,
        ];
    }

    /**
     * Build integrity metadata block for injection into metric outputs.
     */
    protected function integrityMeta(): array
    {
        return [
            'analysis_integrity_score' => $this->getIntegrityScore(),
            'degraded' => $this->isDegraded(),
        ];
    }

    /**
     * Compute percentile_leq normalization.
     *
     * Formula: norm(x) = count(values ≤ x) / total_count
     * Ties get same rank. Frozen edge cases:
     *   total_count == 0 → 0
     *   total_count == 1 → 1
     *
     * @param float $value The value to normalize
     * @param float[] $allValues All values in the population
     * @return float Normalized value in [0, 1]
     */
    protected function percentileLeq(float $value, array $allValues): float
    {
        $total = count($allValues);
        if ($total === 0) {
            return 0.0;
        }
        if ($total === 1) {
            return 1.0;
        }
        $leqCount = 0;
        foreach ($allValues as $v) {
            if ($v <= $value) {
                $leqCount++;
            }
        }
        return round($leqCount / $total, 4);
    }

    /**
     * Cap an evidence array to max_evidence_per_edge from config.
     * Returns array with evidence items, plus truncation metadata if capped.
     *
     * Evidence truncation does NOT affect metric computation (coupling, centrality,
     * hotspots). Only provenance payload is capped. Metrics use edge existence
     * and type, not evidence count.
     *
     * @param array $evidence Array of evidence arrays (already toArray()'d)
     * @return array{evidence: array, evidence_truncated?: true, total_evidence_found?: int}
     */
    protected function capEvidence(array $evidence): array
    {
        $max = 5;
        if ($this->context !== null) {
            $max = $this->config()->getMaxEvidencePerEdge();
        }

        $total = count($evidence);
        if ($total <= $max) {
            return ['evidence' => $evidence];
        }

        return [
            'evidence' => array_slice($evidence, 0, $max),
            'evidence_truncated' => true,
            'total_evidence_found' => $total,
        ];
    }

    /**
     * Create evidence from XML source.
     */
    protected function xmlEvidence(string $sourceFile, string $notes = '', float $confidence = 1.0): Evidence
    {
        return Evidence::fromXml($sourceFile, $notes, $confidence);
    }

    /**
     * Create evidence from PHP AST.
     */
    protected function astEvidence(string $sourceFile, int $line, ?int $lineEnd = null, string $notes = '', float $confidence = 1.0): Evidence
    {
        return Evidence::fromPhpAst($sourceFile, $line, $lineEnd, $notes, $confidence);
    }

    /**
     * Create evidence from inference.
     */
    protected function inferenceEvidence(string $notes, float $confidence = 0.5): Evidence
    {
        return Evidence::fromInference($notes, $confidence);
    }
}

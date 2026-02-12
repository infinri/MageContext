<?php

declare(strict_types=1);

namespace MageContext\Identity;

/**
 * Typed warning collection with categorization and analysis integrity scoring.
 *
 * Warning categories:
 * - unresolved_class: FQCN that could not be mapped to a module
 * - ambiguous_di: DI resolution with multiple candidates or unclear load order
 * - invalid_xml: XML file that failed to parse
 * - missing_module: module.xml referenced but not found
 * - unresolved_file: file path that could not be mapped to a module
 * - general: uncategorized warning
 *
 * Analysis integrity score (ratio-based, not count-based):
 * Starts at 1.0 (perfect). Degrades proportionally:
 * - unresolved_class: subtract min(0.4, unresolved / total_symbols × 0.4)
 * - ambiguous_di: subtract min(0.2, ambiguous / total_di_targets × 0.2)
 * - invalid_xml: subtract min(0.3, count × 0.1)
 * Clamped to [0, 1].
 *
 * If score < 1.0, metrics that depend on completeness (coupling, centrality,
 * hotspots) should include `analysis_integrity_score` and `degraded: true`.
 */
class WarningCollector
{
    public const CAT_UNRESOLVED_CLASS = 'unresolved_class';
    public const CAT_AMBIGUOUS_DI = 'ambiguous_di';
    public const CAT_INVALID_XML = 'invalid_xml';
    public const CAT_MISSING_MODULE = 'missing_module';
    public const CAT_UNRESOLVED_FILE = 'unresolved_file';
    public const CAT_GENERAL = 'general';

    private const ALL_CATEGORIES = [
        self::CAT_UNRESOLVED_CLASS,
        self::CAT_AMBIGUOUS_DI,
        self::CAT_INVALID_XML,
        self::CAT_MISSING_MODULE,
        self::CAT_UNRESOLVED_FILE,
        self::CAT_GENERAL,
    ];

    /**
     * @var array<array{category: string, message: string, extractor: string}>
     */
    private array $warnings = [];

    /**
     * Totals used for ratio-based integrity scoring.
     * Set by the compiler after extraction completes.
     */
    private int $totalSymbols = 0;
    private int $totalDiTargets = 0;

    /**
     * Add a typed warning.
     */
    public function add(string $category, string $message, string $extractor = ''): void
    {
        if (!in_array($category, self::ALL_CATEGORIES, true)) {
            $category = self::CAT_GENERAL;
        }

        $this->warnings[] = [
            'category' => $category,
            'message' => $message,
            'extractor' => $extractor,
        ];
    }

    /**
     * Add a general (untyped) warning — backward compatibility.
     */
    public function addGeneral(string $message, string $extractor = ''): void
    {
        $this->add(self::CAT_GENERAL, $message, $extractor);
    }

    /**
     * Get all warnings.
     *
     * @return array<array{category: string, message: string, extractor: string}>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    /**
     * Get warnings for a specific extractor and clear them.
     *
     * @return array<array{category: string, message: string}>
     */
    public function drainForExtractor(string $extractor): array
    {
        $result = [];
        $remaining = [];

        foreach ($this->warnings as $w) {
            if ($w['extractor'] === $extractor) {
                $result[] = [
                    'category' => $w['category'],
                    'message' => $w['message'],
                ];
            } else {
                $remaining[] = $w;
            }
        }

        $this->warnings = $remaining;
        return $result;
    }

    /**
     * Count warnings by category.
     *
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        $counts = [];
        foreach (self::ALL_CATEGORIES as $cat) {
            $counts[$cat] = 0;
        }
        foreach ($this->warnings as $w) {
            $counts[$w['category']] = ($counts[$w['category']] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Set totals for ratio-based integrity scoring.
     * Must be called after extraction completes, before buildSummary().
     */
    public function setTotals(int $totalSymbols, int $totalDiTargets): void
    {
        $this->totalSymbols = max(1, $totalSymbols);
        $this->totalDiTargets = max(1, $totalDiTargets);
    }

    /**
     * Build the warnings_summary for manifest output.
     *
     * @return array{counts: array<string, int>, total: int, analysis_integrity_score: float, degraded: bool, integrity_notes: string[]}
     */
    public function buildSummary(): array
    {
        $counts = $this->countByCategory();
        $total = array_sum($counts);

        $score = $this->computeIntegrityScore($counts);
        $notes = $this->buildIntegrityNotes($counts);
        $degraded = $score < 1.0;

        return [
            'counts' => $counts,
            'total' => $total,
            'analysis_integrity_score' => $score,
            'degraded' => $degraded,
            'integrity_basis' => [
                'total_symbols' => $this->totalSymbols,
                'total_di_targets' => $this->totalDiTargets,
            ],
            'integrity_notes' => $notes,
        ];
    }

    /**
     * Get the current integrity score (for injection into metric outputs).
     */
    public function getIntegrityScore(): float
    {
        return $this->computeIntegrityScore($this->countByCategory());
    }

    /**
     * Whether analysis is degraded (score < 1.0).
     */
    public function isDegraded(): bool
    {
        return $this->getIntegrityScore() < 1.0;
    }

    /**
     * Compute analysis integrity score using ratio-based formula.
     *
     * Formula:
     *   1.0
     *   - min(0.4, unresolved_class / total_symbols × 0.4)
     *   - min(0.2, ambiguous_di / total_di_targets × 0.2)
     *   - min(0.3, invalid_xml × 0.1)
     *   - min(0.1, missing_module × 0.05)
     * Clamped to [0, 1]
     */
    private function computeIntegrityScore(array $counts): float
    {
        $score = 1.0;

        $unresolvedClass = $counts[self::CAT_UNRESOLVED_CLASS] ?? 0;
        if ($unresolvedClass > 0) {
            $score -= min(0.4, ($unresolvedClass / $this->totalSymbols) * 0.4);
        }

        $ambiguousDi = $counts[self::CAT_AMBIGUOUS_DI] ?? 0;
        if ($ambiguousDi > 0) {
            $score -= min(0.2, ($ambiguousDi / $this->totalDiTargets) * 0.2);
        }

        $invalidXml = $counts[self::CAT_INVALID_XML] ?? 0;
        if ($invalidXml > 0) {
            $score -= min(0.3, $invalidXml * 0.1);
        }

        $missingModule = $counts[self::CAT_MISSING_MODULE] ?? 0;
        if ($missingModule > 0) {
            $score -= min(0.1, $missingModule * 0.05);
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * Build human-readable integrity notes.
     *
     * @return string[]
     */
    private function buildIntegrityNotes(array $counts): array
    {
        $notes = [];

        if (($counts[self::CAT_UNRESOLVED_CLASS] ?? 0) > 0) {
            $n = $counts[self::CAT_UNRESOLVED_CLASS];
            $notes[] = "{$n} unresolved class(es) — coupling metrics may be incomplete";
        }

        if (($counts[self::CAT_AMBIGUOUS_DI] ?? 0) > 0) {
            $n = $counts[self::CAT_AMBIGUOUS_DI];
            $notes[] = "{$n} ambiguous DI resolution(s) — DI resolution map confidence degraded";
        }

        if (($counts[self::CAT_INVALID_XML] ?? 0) > 0) {
            $n = $counts[self::CAT_INVALID_XML];
            $notes[] = "{$n} invalid XML file(s) — DI/plugin/observer/route graphs may be incomplete";
        }

        if (($counts[self::CAT_MISSING_MODULE] ?? 0) > 0) {
            $n = $counts[self::CAT_MISSING_MODULE];
            $notes[] = "{$n} missing module(s) — dependency graph has gaps";
        }

        if (($counts[self::CAT_UNRESOLVED_FILE] ?? 0) > 0) {
            $n = $counts[self::CAT_UNRESOLVED_FILE];
            $notes[] = "{$n} unresolved file(s) — module attribution may be inaccurate";
        }

        if (empty($notes)) {
            $notes[] = 'No integrity concerns detected';
        }

        return $notes;
    }

    /**
     * Total warning count.
     */
    public function count(): int
    {
        return count($this->warnings);
    }

    /**
     * Check if any warnings exist.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
}

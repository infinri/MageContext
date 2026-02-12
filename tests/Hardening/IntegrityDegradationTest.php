<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Identity\WarningCollector;
use PHPUnit\Framework\TestCase;

/**
 * Test 2: Integrity Degradation
 *
 * - 100 classes, inject 10 unresolved_class warnings
 * - Integrity score must drop proportionally
 * - Dampening: raw_score stays same, final_score drops
 * - Module ordering must remain identical under dampening
 */
class IntegrityDegradationTest extends TestCase
{
    /**
     * Score drops proportionally with unresolved class ratio.
     */
    public function testIntegrityDropsProportionally(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(100, 50); // 100 symbols, 50 DI targets

        // Perfect score with no warnings
        $this->assertSame(1.0, $collector->getIntegrityScore());
        $this->assertFalse($collector->isDegraded());

        // Add 10 unresolved class warnings (10% of 100 symbols)
        for ($i = 0; $i < 10; $i++) {
            $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, "Unresolved class #{$i}", 'test');
        }

        $score = $collector->getIntegrityScore();

        // 10/100 × 0.4 = 0.04 penalty → score should be ~0.96
        $this->assertTrue($score < 1.0, 'Score must drop below 1.0 with unresolved classes');
        $this->assertTrue($score > 0.9, "Score should be ~0.96 but got {$score}");
        $this->assertTrue($collector->isDegraded());

        // Expected: 1.0 - min(0.4, 10/100 * 0.4) = 1.0 - 0.04 = 0.96
        $this->assertEqualsWithDelta(0.96, $score, 0.001);
    }

    /**
     * Large number of warnings caps at max penalty.
     */
    public function testIntegrityCapsAtMaxPenalty(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(100, 50);

        // Add 200 unresolved class warnings (200% — more than total)
        for ($i = 0; $i < 200; $i++) {
            $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, "Unresolved #{$i}", 'test');
        }

        $score = $collector->getIntegrityScore();

        // min(0.4, 200/100 * 0.4) = min(0.4, 0.8) = 0.4 → score = 0.6
        $this->assertEqualsWithDelta(0.6, $score, 0.001);
    }

    /**
     * Multiple warning categories compound.
     */
    public function testMultipleCategoriesCompound(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(100, 50);

        // 10 unresolved classes: -0.04
        for ($i = 0; $i < 10; $i++) {
            $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, "Unresolved #{$i}", 'test');
        }

        // 5 ambiguous DI: 5/50 * 0.2 = 0.02
        for ($i = 0; $i < 5; $i++) {
            $collector->add(WarningCollector::CAT_AMBIGUOUS_DI, "Ambiguous #{$i}", 'test');
        }

        // 2 invalid XML: 2 * 0.1 = 0.2
        for ($i = 0; $i < 2; $i++) {
            $collector->add(WarningCollector::CAT_INVALID_XML, "Invalid #{$i}", 'test');
        }

        $score = $collector->getIntegrityScore();

        // 1.0 - 0.04 - 0.02 - 0.2 = 0.74
        $this->assertEqualsWithDelta(0.74, $score, 0.001);
    }

    /**
     * Dampening preserves ordering.
     *
     * If module A has raw_score > module B,
     * then A.final_score > B.final_score (same integrity multiplier).
     */
    public function testDampeningPreservesOrdering(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(100, 50);

        // Add warnings to degrade integrity
        for ($i = 0; $i < 20; $i++) {
            $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, "Unresolved #{$i}", 'test');
        }

        $integrity = $collector->getIntegrityScore();
        $this->assertTrue($integrity < 1.0);

        // Simulate 5 modules with different raw scores
        $rawScores = [0.9, 0.7, 0.5, 0.3, 0.1];
        $finalScores = array_map(fn($raw) => $raw * $integrity, $rawScores);

        // Ordering must be preserved
        for ($i = 0; $i < count($rawScores) - 1; $i++) {
            $this->assertGreaterThan(
                $finalScores[$i + 1],
                $finalScores[$i],
                "Final score ordering must match raw score ordering at index {$i}"
            );
        }

        // Final scores must be strictly less than raw (since integrity < 1)
        for ($i = 0; $i < count($rawScores); $i++) {
            if ($rawScores[$i] > 0) {
                $this->assertLessThan($rawScores[$i], $finalScores[$i]);
            }
        }
    }

    /**
     * buildSummary includes integrity_basis with correct totals.
     */
    public function testSummaryIncludesIntegrityBasis(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(100, 50);
        $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, 'test', 'test');

        $summary = $collector->buildSummary();

        $this->assertArrayHasKey('analysis_integrity_score', $summary);
        $this->assertArrayHasKey('degraded', $summary);
        $this->assertArrayHasKey('integrity_basis', $summary);
        $this->assertArrayHasKey('integrity_notes', $summary);
        $this->assertArrayHasKey('counts', $summary);

        $this->assertTrue($summary['degraded']);
        $this->assertSame(100, $summary['integrity_basis']['total_symbols']);
        $this->assertSame(50, $summary['integrity_basis']['total_di_targets']);
    }

    /**
     * Zero warnings → perfect score.
     */
    public function testZeroWarningsPerfectScore(): void
    {
        $collector = new WarningCollector();
        $collector->setTotals(500, 200);

        $this->assertSame(1.0, $collector->getIntegrityScore());
        $this->assertFalse($collector->isDegraded());

        $summary = $collector->buildSummary();
        $this->assertSame(0, $summary['total']);
        $this->assertContains('No integrity concerns detected', $summary['integrity_notes']);
    }

    /**
     * drainForExtractor returns correct warnings and removes them.
     */
    public function testDrainForExtractorIsolation(): void
    {
        $collector = new WarningCollector();
        $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, 'class A', 'extractor_a');
        $collector->add(WarningCollector::CAT_INVALID_XML, 'file B', 'extractor_b');
        $collector->add(WarningCollector::CAT_UNRESOLVED_CLASS, 'class C', 'extractor_a');

        $drainedA = $collector->drainForExtractor('extractor_a');
        $this->assertCount(2, $drainedA);
        $this->assertSame('unresolved_class', $drainedA[0]['category']);
        $this->assertSame('unresolved_class', $drainedA[1]['category']);

        // After draining A, only B's warning remains
        $this->assertSame(1, $collector->count());

        $drainedB = $collector->drainForExtractor('extractor_b');
        $this->assertCount(1, $drainedB);
        $this->assertSame(0, $collector->count());
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Extractor\AbstractExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Test helper to access protected percentileLeq method.
 */
class PercentileTestExtractor extends AbstractExtractor
{
    public function getName(): string { return 'test'; }
    public function getDescription(): string { return 'test'; }
    public function getOutputView(): string { return '.'; }
    public function extract(string $repoPath, array $scopes): array { return []; }

    public function testPercentileLeq(float $value, array $allValues): float
    {
        return $this->percentileLeq($value, $allValues);
    }

    public function testDampenScore(float $rawScore): array
    {
        return $this->dampenScore($rawScore);
    }
}

/**
 * Test percentile_leq normalization edge cases and dampening math.
 */
class PercentileNormalizationTest extends TestCase
{
    private PercentileTestExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PercentileTestExtractor();
    }

    /**
     * Frozen edge case: total_count == 0 → 0.
     */
    public function testEmptyPopulationReturnsZero(): void
    {
        $result = $this->extractor->testPercentileLeq(5.0, []);
        $this->assertSame(0.0, $result);
    }

    /**
     * Frozen edge case: total_count == 1 → 1.
     */
    public function testSingleElementReturnsOne(): void
    {
        $result = $this->extractor->testPercentileLeq(42.0, [42.0]);
        $this->assertSame(1.0, $result);
    }

    /**
     * Normal case: percentile of lowest value.
     */
    public function testLowestValuePercentile(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $result = $this->extractor->testPercentileLeq(1.0, $values);
        // 1 value ≤ 1 out of 5 = 0.2
        $this->assertEqualsWithDelta(0.2, $result, 0.0001);
    }

    /**
     * Normal case: percentile of highest value.
     */
    public function testHighestValuePercentile(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $result = $this->extractor->testPercentileLeq(5.0, $values);
        // 5 values ≤ 5 out of 5 = 1.0
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    /**
     * Normal case: percentile of middle value.
     */
    public function testMiddleValuePercentile(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];
        $result = $this->extractor->testPercentileLeq(3.0, $values);
        // 3 values ≤ 3 out of 5 = 0.6
        $this->assertEqualsWithDelta(0.6, $result, 0.0001);
    }

    /**
     * Ties get same rank.
     */
    public function testTiesGetSameRank(): void
    {
        $values = [1.0, 3.0, 3.0, 3.0, 5.0];

        $result = $this->extractor->testPercentileLeq(3.0, $values);
        // 4 values ≤ 3 out of 5 = 0.8
        $this->assertEqualsWithDelta(0.8, $result, 0.0001);
    }

    /**
     * All same values: everyone at 100th percentile.
     */
    public function testAllSameValues(): void
    {
        $values = [5.0, 5.0, 5.0, 5.0];
        $result = $this->extractor->testPercentileLeq(5.0, $values);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    /**
     * Two elements.
     */
    public function testTwoElements(): void
    {
        $values = [10.0, 20.0];

        $low = $this->extractor->testPercentileLeq(10.0, $values);
        $high = $this->extractor->testPercentileLeq(20.0, $values);

        // 1/2 = 0.5 for low, 2/2 = 1.0 for high
        $this->assertEqualsWithDelta(0.5, $low, 0.0001);
        $this->assertEqualsWithDelta(1.0, $high, 0.0001);
    }

    /**
     * Dampening without context → integrity = 1.0 (no dampening).
     */
    public function testDampenScoreNoContext(): void
    {
        $result = $this->extractor->testDampenScore(0.75);

        $this->assertArrayHasKey('raw_score', $result);
        $this->assertArrayHasKey('final_score', $result);
        $this->assertArrayHasKey('integrity_score_used', $result);

        // Without context, integrity = 1.0, so final = raw
        $this->assertEqualsWithDelta(0.75, $result['raw_score'], 0.0001);
        $this->assertEqualsWithDelta(0.75, $result['final_score'], 0.0001);
        $this->assertSame(1.0, $result['integrity_score_used']);
    }

    /**
     * Percentile normalization preserves ordering.
     */
    public function testPercentilePreservesOrdering(): void
    {
        $values = [3.0, 7.0, 1.0, 9.0, 5.0];

        $normalized = [];
        foreach ($values as $v) {
            $normalized[] = $this->extractor->testPercentileLeq($v, $values);
        }

        // Sort by original value
        $pairs = array_map(null, $values, $normalized);
        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);

        // Normalized values must be non-decreasing
        for ($i = 0; $i < count($pairs) - 1; $i++) {
            $this->assertLessThanOrEqual(
                $pairs[$i + 1][1],
                $pairs[$i][1],
                "Percentile must preserve ordering"
            );
        }
    }
}

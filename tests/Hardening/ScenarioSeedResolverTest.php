<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Output\ScenarioSeedResolver;
use PHPUnit\Framework\TestCase;

/**
 * Test ScenarioSeedResolver: canonical JSON, recursive ksort, deduplication.
 */
class ScenarioSeedResolverTest extends TestCase
{
    /**
     * canonicalJson uses recursive ksort — not just top-level.
     */
    public function testCanonicalJsonRecursiveSort(): void
    {
        $dataA = ['type' => 'route', 'meta' => ['z' => 1, 'a' => 2], 'area' => 'frontend'];
        $dataB = ['area' => 'frontend', 'type' => 'route', 'meta' => ['a' => 2, 'z' => 1]];

        $jsonA = ScenarioSeedResolver::canonicalJson($dataA);
        $jsonB = ScenarioSeedResolver::canonicalJson($dataB);

        $this->assertSame($jsonA, $jsonB, 'canonicalJson must produce identical output regardless of key order (including nested)');
    }

    /**
     * Same canonical entry → same scenario_id.
     */
    public function testSameEntryProducesSameId(): void
    {
        $entryA = ['type' => 'route', 'area' => 'frontend', 'route_id' => 'catalog_product_view'];
        $entryB = ['route_id' => 'catalog_product_view', 'type' => 'route', 'area' => 'frontend'];

        $idA = ScenarioSeedResolver::scenarioId($entryA);
        $idB = ScenarioSeedResolver::scenarioId($entryB);

        $this->assertSame($idA, $idB, 'Same entry with different key order must produce same scenario_id');
    }

    /**
     * Different entries → different scenario_ids.
     */
    public function testDifferentEntriesProduceDifferentIds(): void
    {
        $route = ['type' => 'route', 'area' => 'frontend', 'route_id' => 'catalog_product_view'];
        $cron = ['type' => 'cron', 'group' => 'default', 'cron_id' => 'catalog_product_alert'];

        $this->assertNotSame(
            ScenarioSeedResolver::scenarioId($route),
            ScenarioSeedResolver::scenarioId($cron)
        );
    }

    /**
     * resolve() deduplicates by scenario_id.
     */
    public function testResolveDeduplicates(): void
    {
        $resolver = new ScenarioSeedResolver();

        $data = [
            'route_map' => [
                'routes' => [
                    ['route_id' => 'catalog_product_view', 'area' => 'frontend'],
                    ['route_id' => 'catalog_product_view', 'area' => 'frontend'], // duplicate
                    ['route_id' => 'checkout_index', 'area' => 'frontend'],
                ],
            ],
            'cron_map' => [
                'cron_jobs' => [
                    ['cron_id' => 'catalog_product_alert', 'group' => 'default'],
                ],
            ],
        ];

        $seeds = $resolver->resolve($data);

        // 3 unique entries (1 duplicate route removed)
        $this->assertCount(3, $seeds);

        // Each seed has scenario_id and canonical_entry
        foreach ($seeds as $seed) {
            $this->assertArrayHasKey('scenario_id', $seed);
            $this->assertArrayHasKey('canonical_entry', $seed);
            $this->assertNotEmpty($seed['scenario_id']);
        }
    }

    /**
     * resolve() collects from all 4 entrypoint sources.
     */
    public function testResolveCollectsAllSources(): void
    {
        $resolver = new ScenarioSeedResolver();

        $data = [
            'route_map' => ['routes' => [
                ['route_id' => 'r1', 'area' => 'frontend'],
            ]],
            'cron_map' => ['cron_jobs' => [
                ['cron_id' => 'c1', 'group' => 'default'],
            ]],
            'cli_commands' => ['commands' => [
                ['command_name' => 'setup:upgrade'],
            ]],
            'api_surface' => ['endpoints' => [
                ['method' => 'GET', 'path' => '/V1/products'],
            ]],
        ];

        $seeds = $resolver->resolve($data);
        $this->assertCount(4, $seeds);

        $types = array_column(array_column($seeds, 'canonical_entry'), 'type');
        sort($types);
        $this->assertSame(['api', 'cli', 'cron', 'route'], $types);
    }

    /**
     * canonicalJson uses JSON_UNESCAPED_SLASHES.
     */
    public function testCanonicalJsonUnescapedSlashes(): void
    {
        $data = ['type' => 'api', 'path' => '/V1/products/:sku'];
        $json = ScenarioSeedResolver::canonicalJson($data);

        $this->assertStringContainsString('/V1/products/:sku', $json);
        $this->assertStringNotContainsString('\/', $json);
    }

    /**
     * Empty extractor data → empty seeds.
     */
    public function testEmptyDataProducesEmptySeeds(): void
    {
        $resolver = new ScenarioSeedResolver();
        $seeds = $resolver->resolve([]);
        $this->assertEmpty($seeds);
    }
}

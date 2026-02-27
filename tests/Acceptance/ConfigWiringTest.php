<?php

declare(strict_types=1);

namespace MageContext\Tests\Acceptance;

use MageContext\Config\CompilerConfig;
use PHPUnit\Framework\TestCase;

/**
 * D.3: Config wiring acceptance tests.
 *
 * Verifies that .magecontext.json → CompilerConfig → accessors
 * work end-to-end, including defaults, file overrides, and CLI overrides.
 */
class ConfigWiringTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/magecontext-config-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = array_merge(
                glob($this->tmpDir . '/*'),
                glob($this->tmpDir . '/.*')
            );
            foreach ($files as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            rmdir($this->tmpDir);
        }
    }

    public function testDefaultsLoadWithoutConfigFile(): void
    {
        $config = CompilerConfig::load($this->tmpDir);

        $this->assertSame(['app/code', 'app/design'], $config->getScopes());
        $this->assertFalse($config->includeVendor());
        $this->assertSame('json', $config->getOutputFormat());
        $this->assertSame(5, $config->getMaxEvidencePerEdge());
        $this->assertTrue($config->isChurnEnabled());
        $this->assertSame(365, $config->getChurnWindowDays());
        $this->assertTrue($config->isChurnCacheEnabled());
        $this->assertSame(12, $config->getMaxReverseIndexSizeMb());
    }

    public function testConfigFileOverridesDefaults(): void
    {
        file_put_contents($this->tmpDir . '/.magecontext.json', json_encode([
            'scopes' => ['app/code', 'vendor'],
            'include_vendor' => true,
            'max_evidence_per_edge' => 10,
            'churn' => [
                'window_days' => 90,
            ],
            'max_reverse_index_size_mb' => 20,
        ]));

        $config = CompilerConfig::load($this->tmpDir);

        $this->assertSame(['app/code', 'vendor'], $config->getScopes());
        $this->assertTrue($config->includeVendor());
        $this->assertSame(10, $config->getMaxEvidencePerEdge());
        $this->assertSame(90, $config->getChurnWindowDays());
        $this->assertTrue($config->isChurnEnabled()); // not overridden → default
        $this->assertSame(20, $config->getMaxReverseIndexSizeMb());
    }

    public function testCliOverridesConfigFile(): void
    {
        file_put_contents($this->tmpDir . '/.magecontext.json', json_encode([
            'churn' => [
                'window_days' => 90,
                'enabled' => true,
            ],
        ]));

        // CLI says disable churn
        $config = CompilerConfig::load($this->tmpDir, [
            'churn' => ['enabled' => false],
        ]);

        $this->assertFalse($config->isChurnEnabled());
        // window_days from file still preserved (deep merge)
        $this->assertSame(90, $config->getChurnWindowDays());
    }

    public function testEdgeWeightsAccessor(): void
    {
        $config = CompilerConfig::load($this->tmpDir);
        $weights = $config->getEdgeWeights();

        $this->assertArrayHasKey('module_sequence', $weights);
        $this->assertArrayHasKey('plugin_intercept', $weights);
        $this->assertSame(0.7, $weights['module_sequence']);
        $this->assertSame(1.2, $weights['plugin_intercept']);

        // Unknown edge type returns 1.0 (safe default)
        $this->assertSame(1.0, $config->getEdgeWeight('nonexistent_type'));
    }

    public function testCentralityEdgeTypesExplicit(): void
    {
        $config = CompilerConfig::load($this->tmpDir);
        $types = $config->getCentralityEdgeTypes();

        $this->assertContains('module_sequence', $types);
        $this->assertContains('plugin_intercept', $types);
        // php_symbol_use deliberately excluded from centrality
        $this->assertNotContains('php_symbol_use', $types);
    }

    public function testCouplingMetricSubsets(): void
    {
        $config = CompilerConfig::load($this->tmpDir);
        $subsets = $config->getCouplingMetricSubsets();

        $this->assertArrayHasKey('structural', $subsets);
        $this->assertArrayHasKey('code', $subsets);
        $this->assertArrayHasKey('runtime', $subsets);
        $this->assertContains('module_sequence', $subsets['structural']);
        $this->assertContains('php_symbol_use', $subsets['code']);
        $this->assertContains('di_preference', $subsets['runtime']);
    }

    public function testInvalidConfigFileIgnored(): void
    {
        file_put_contents($this->tmpDir . '/.magecontext.json', 'not valid json');
        $config = CompilerConfig::load($this->tmpDir);

        // Should fall back to defaults
        $this->assertSame(['app/code', 'app/design'], $config->getScopes());
    }

    public function testToArrayRoundTrip(): void
    {
        $config = CompilerConfig::load($this->tmpDir);
        $arr = $config->toArray();

        $this->assertArrayHasKey('scopes', $arr);
        $this->assertArrayHasKey('edge_weights', $arr);
        $this->assertArrayHasKey('churn', $arr);
        $this->assertArrayHasKey('centrality_edge_types', $arr);
        $this->assertArrayHasKey('max_reverse_index_size_mb', $arr);
    }

    public function testExampleConfigIsValidJson(): void
    {
        $examplePath = __DIR__ . '/../../.magecontext.example.json';
        $this->assertFileExists($examplePath);

        $content = file_get_contents($examplePath);
        $parsed = json_decode($content, true);
        $this->assertIsArray($parsed, 'Example config must be valid JSON');

        // Verify it has all the key sections
        $this->assertArrayHasKey('scopes', $parsed);
        $this->assertArrayHasKey('edge_weights', $parsed);
        $this->assertArrayHasKey('churn', $parsed);
        $this->assertArrayHasKey('centrality_edge_types', $parsed);
        $this->assertArrayHasKey('max_reverse_index_size_mb', $parsed);
        $this->assertArrayHasKey('thresholds', $parsed);
        $this->assertArrayHasKey('coupling_metric_subsets', $parsed);
    }
}

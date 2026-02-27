<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Config\CompilerConfig;
use MageContext\Output\BundleValidator;
use MageContext\Output\OutputWriter;
use MageContext\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test 5: Edge Weight Failure
 *
 * - Remove weight for plugin_intercept but leave in centrality_edge_types → error
 * - Define weight for fake edge type not emitted → optional warning
 * - Both rules present and correct → passes
 */
class EdgeWeightFailureTest extends TestCase
{
    use TempDirectoryTrait;

    protected function setUp(): void
    {
        $this->createTmpDir('edge-weight');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Edge type in centrality_edge_types but NOT in edge_weights → validation error.
     */
    public function testMissingWeightForCentralityTypeIsError(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Use a phantom type that doesn't exist in defaults
        $config = CompilerConfig::load($this->tmpDir, [
            'centrality_edge_types' => ['module_sequence', 'phantom_edge_type'],
            // phantom_edge_type is NOT in default edge_weights
        ]);

        $validator = new BundleValidator($config, $writer, $this->tmpDir);
        $result = $validator->validate([], true, ['module_sequence', 'phantom_edge_type']);

        // Must have error for missing weight
        $this->assertFalse($result['passed'], 'Validation must fail when centrality type has no weight');

        $errorRules = array_column($result['errors'], 'rule');
        $this->assertContains('centrality_missing_weight', $errorRules);

        // Find the specific error message
        $missingErrors = array_filter($result['errors'], fn($e) => $e['rule'] === 'centrality_missing_weight');
        $errorMsg = reset($missingErrors)['message'];
        $this->assertStringContainsString('phantom_edge_type', $errorMsg);
    }

    /**
     * Edge type in edge_weights but never emitted → optional warning (not error).
     */
    public function testUnusedWeightIsWarning(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        $config = CompilerConfig::load($this->tmpDir, [
            'centrality_edge_types' => ['module_sequence'],
            'edge_weights' => [
                'ghost_edge_type' => 1.5,  // defined but never emitted
            ],
        ]);

        // Emit all default weight types EXCEPT ghost_edge_type
        $allDefaultWeights = array_keys($config->getEdgeWeights());
        $emittedTypes = array_filter($allDefaultWeights, fn($t) => $t !== 'ghost_edge_type');

        $validator = new BundleValidator($config, $writer, $this->tmpDir);
        $result = $validator->validate([], true, array_values($emittedTypes));

        // Should pass (warnings are not errors)
        $this->assertTrue($result['passed'], 'Unused weights should warn, not fail');

        // Must have warning about unused weight
        $warningRules = array_column($result['warnings'], 'rule');
        $this->assertContains('edge_weight_never_emitted', $warningRules);

        // Find the specific warning message for ghost_edge_type
        $ghostWarnings = array_filter($result['warnings'], fn($w) =>
            $w['rule'] === 'edge_weight_never_emitted' &&
            str_contains($w['message'], 'ghost_edge_type')
        );
        $this->assertNotEmpty($ghostWarnings, 'Must warn specifically about ghost_edge_type');
    }

    /**
     * All centrality types have weights AND all weights are emitted → clean pass.
     */
    public function testMatchingConfigPassesClean(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Use default config — all centrality types have weights
        $config = CompilerConfig::load($this->tmpDir);

        // Emit ALL weight types so no "never emitted" warnings
        $emittedTypes = array_keys($config->getEdgeWeights());

        $validator = new BundleValidator($config, $writer, $this->tmpDir);
        $result = $validator->validate([], true, $emittedTypes);

        $this->assertTrue($result['passed']);
        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
    }

    /**
     * Multiple centrality types missing weights → multiple errors.
     */
    public function testMultipleMissingWeightsMultipleErrors(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        $config = CompilerConfig::load($this->tmpDir, [
            'centrality_edge_types' => ['type_a', 'type_b', 'type_c'],
            'edge_weights' => [
                'type_a' => 1.0,
                // type_b and type_c missing
            ],
        ]);

        $validator = new BundleValidator($config, $writer, $this->tmpDir);
        $result = $validator->validate([], true, ['type_a']);

        $this->assertFalse($result['passed']);

        $missingErrors = array_filter($result['errors'], fn($e) => $e['rule'] === 'centrality_missing_weight');
        $this->assertCount(2, $missingErrors, 'Should have 2 errors for type_b and type_c');
    }

    /**
     * Default config should be consistent (no centrality types missing weights).
     */
    public function testDefaultConfigIsConsistent(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        $config = CompilerConfig::load($this->tmpDir);
        $centralityTypes = $config->getCentralityEdgeTypes();
        $edgeWeights = $config->getEdgeWeights();

        foreach ($centralityTypes as $type) {
            $this->assertArrayHasKey(
                $type,
                $edgeWeights,
                "Default config: centrality type '{$type}' must have a weight defined"
            );
        }
    }

}

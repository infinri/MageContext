<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Config\CompilerConfig;
use MageContext\Output\BundleValidator;
use MageContext\Output\OutputWriter;
use PHPUnit\Framework\TestCase;

/**
 * Test 1: Corruption Tests
 *
 * Validator must fail loudly when:
 * - Evidence is missing from edges
 * - Edge type not in edge_weights but in centrality_edge_types
 * - Output file is not deterministic (modified after normalization)
 */
class CorruptionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/context-compiler-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Missing evidence on edges → validator warns about missing evidence.
     */
    public function testMissingEvidenceOnEdges(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Write a dependency graph with edges missing evidence
        $data = [
            'edges' => [
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_ModuleA'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_ModuleB'],
                    'edge_type' => 'di_preference',
                    'weight' => 3,
                    // NO evidence key — corruption
                ],
            ],
            'coupling_metrics' => [],
            'summary' => ['total_edges' => 1],
        ];

        $writer->writeJson('module_view/dependencies.json', $data);

        $config = CompilerConfig::load($this->tmpDir);
        $validator = new BundleValidator($config, $writer, $this->tmpDir);

        $extractorResults = [
            'dependencies' => [
                'status' => 'ok',
                'item_count' => 1,
                'view' => 'module_view',
                'output_files' => ['module_view/dependencies.json'],
                'warnings' => [],
            ],
        ];

        // Deliberately skip determinism (we're testing evidence check)
        $result = $validator->validate($extractorResults, true);

        // Must have warnings about missing evidence
        $allResults = array_merge($result['warnings'], $result['errors']);
        $evidenceResults = array_filter($allResults, fn($w) => $w['rule'] === 'missing_evidence');
        $this->assertNotEmpty($evidenceResults, 'Validator must warn when evidence is missing from edges');
    }

    /**
     * Edge type in centrality_edge_types but not in edge_weights → error.
     */
    public function testCentralityTypeMissingWeight(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Create config with centrality type that has no weight
        $config = CompilerConfig::load($this->tmpDir, [
            'centrality_edge_types' => ['module_sequence', 'phantom_type'],
            'edge_weights' => ['module_sequence' => 0.7],
            // phantom_type deliberately NOT in edge_weights
        ]);

        $validator = new BundleValidator($config, $writer, $this->tmpDir);
        $result = $validator->validate([], true, ['module_sequence']);

        $errors = $result['errors'];
        $missingWeightErrors = array_filter($errors, fn($e) => $e['rule'] === 'centrality_missing_weight');
        $this->assertNotEmpty($missingWeightErrors, 'Validator must error when centrality type has no weight');
        $this->assertFalse($result['passed'], 'Validation must fail');
    }

    /**
     * Output file not deterministic after re-normalization → error.
     */
    public function testNonDeterministicOutputFails(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Write a valid JSON file through the writer (normalized)
        $data = ['modules' => [['module_id' => 'A'], ['module_id' => 'B']]];
        $writer->writeJson('module_view/module_graph.json', $data);

        // Now corrupt the file by reordering keys (bypass normalization)
        $filePath = $this->tmpDir . '/module_view/module_graph.json';
        $content = json_decode(file_get_contents($filePath), true);
        // Reverse the key order manually
        $corrupted = array_reverse($content, true);
        file_put_contents($filePath, json_encode($corrupted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $config = CompilerConfig::load($this->tmpDir);
        $validator = new BundleValidator($config, $writer, $this->tmpDir);

        $extractorResults = [
            'module_graph' => [
                'status' => 'ok',
                'item_count' => 2,
                'view' => 'module_view',
                'output_files' => ['module_view/module_graph.json'],
            ],
        ];

        // DO NOT skip determinism check
        $result = $validator->validate($extractorResults, false);

        $errors = $result['errors'];
        $determinismErrors = array_filter($errors, fn($e) => $e['rule'] === 'determinism_mismatch');
        $this->assertNotEmpty($determinismErrors, 'Validator must fail when output is not deterministic');
        $this->assertFalse($result['passed'], 'Validation must fail on non-deterministic output');
    }

    /**
     * All checks pass on clean output.
     */
    public function testCleanOutputPasses(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        $config = CompilerConfig::load($this->tmpDir);
        $validator = new BundleValidator($config, $writer, $this->tmpDir);

        // No extractors, no files — should pass cleanly
        $result = $validator->validate([], true, array_keys($config->getEdgeWeights()));

        $this->assertTrue($result['passed'], 'Clean validation should pass');
        $this->assertEmpty($result['errors'], 'No errors expected on clean run');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

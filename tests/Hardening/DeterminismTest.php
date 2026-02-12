<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Output\OutputWriter;
use PHPUnit\Framework\TestCase;

/**
 * Test 3: Determinism Stress
 *
 * - Write same data multiple times → identical output
 * - Reorder input keys/arrays → same normalized output
 * - normalize() is truly schema-aware and idempotent
 */
class DeterminismTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/magecontext-determinism-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /**
     * Writing same data 5 times must produce identical files.
     */
    public function testRepeatedWritesIdentical(): void
    {
        $data = [
            'edges' => [
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_ModuleB'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_ModuleA'],
                    'edge_type' => 'di_preference',
                    'weight' => 3,
                    'evidence' => [['type' => 'xml', 'source_file' => 'app/code/Vendor/ModuleB/etc/di.xml']],
                ],
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_ModuleA'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_ModuleC'],
                    'edge_type' => 'module_sequence',
                    'weight' => 1,
                    'evidence' => [['type' => 'xml', 'source_file' => 'app/code/Vendor/ModuleA/etc/module.xml']],
                ],
            ],
            'summary' => ['total_edges' => 2],
        ];

        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            $writer = new OutputWriter($this->tmpDir);
            $writer->prepare();
            // Use addMetadata=false to exclude generated_at timestamp
            $writer->writeJson("run_{$i}.json", $data, false);

            $content = file_get_contents($this->tmpDir . "/run_{$i}.json");
            $hashes[] = sha1($content);
        }

        // All 5 hashes must be identical
        $unique = array_unique($hashes);
        $this->assertCount(1, $unique, 'All 5 runs must produce identical output. Got ' . count($unique) . ' different hashes.');
    }

    /**
     * Reordering input keys must produce same normalized output.
     */
    public function testKeyReorderProducesSameOutput(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Data with keys in order A
        $dataA = [
            'summary' => ['total' => 1],
            'edges' => [
                [
                    'weight' => 2,
                    'edge_type' => 'di_preference',
                    'to' => ['id' => 'B', 'kind' => 'module'],
                    'from' => ['id' => 'A', 'kind' => 'module'],
                    'evidence' => [],
                ],
            ],
        ];

        // Data with keys in order B (different order, same content)
        $dataB = [
            'edges' => [
                [
                    'from' => ['kind' => 'module', 'id' => 'A'],
                    'to' => ['kind' => 'module', 'id' => 'B'],
                    'edge_type' => 'di_preference',
                    'evidence' => [],
                    'weight' => 2,
                ],
            ],
            'summary' => ['total' => 1],
        ];

        // Use addMetadata=false to exclude $schema differences
        $writer->writeJson('orderA.json', $dataA, false);
        $writer->writeJson('orderB.json', $dataB, false);

        $contentA = file_get_contents($this->tmpDir . '/orderA.json');
        $contentB = file_get_contents($this->tmpDir . '/orderB.json');

        $this->assertSame($contentA, $contentB, 'Different key ordering must normalize to identical output');
    }

    /**
     * Reordering items in arrays must produce same normalized output
     * when items have known sort keys.
     */
    public function testArrayItemReorderProducesSameOutput(): void
    {
        $writer = new OutputWriter($this->tmpDir);
        $writer->prepare();

        // Edges in order B, A
        $dataBA = [
            'edges' => [
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_B'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_C'],
                    'edge_type' => 'plugin_intercept',
                    'weight' => 1,
                    'evidence' => [],
                ],
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_A'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_B'],
                    'edge_type' => 'di_preference',
                    'weight' => 2,
                    'evidence' => [],
                ],
            ],
        ];

        // Edges in order A, B
        $dataAB = [
            'edges' => [
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_A'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_B'],
                    'edge_type' => 'di_preference',
                    'weight' => 2,
                    'evidence' => [],
                ],
                [
                    'from' => ['kind' => 'module', 'id' => 'Vendor_B'],
                    'to' => ['kind' => 'module', 'id' => 'Vendor_C'],
                    'edge_type' => 'plugin_intercept',
                    'weight' => 1,
                    'evidence' => [],
                ],
            ],
        ];

        $writer->writeJson('order_ba.json', $dataBA, false);
        $writer->writeJson('order_ab.json', $dataAB, false);

        $contentBA = file_get_contents($this->tmpDir . '/order_ba.json');
        $contentAB = file_get_contents($this->tmpDir . '/order_ab.json');

        $this->assertSame($contentBA, $contentAB, 'Different item ordering in edges must normalize to identical output');
    }

    /**
     * normalize() is idempotent: normalizing already-normalized data produces same result.
     */
    public function testNormalizeIsIdempotent(): void
    {
        $writer = new OutputWriter($this->tmpDir);

        $data = [
            'z_key' => 'last',
            'a_key' => 'first',
            'edges' => [
                ['from' => 'B', 'to' => 'C', 'edge_type' => 'x', 'evidence' => []],
                ['from' => 'A', 'to' => 'B', 'edge_type' => 'y', 'evidence' => []],
            ],
        ];

        $pass1 = $writer->normalize($data);
        $pass2 = $writer->normalize($pass1);
        $pass3 = $writer->normalize($pass2);

        $this->assertSame(
            json_encode($pass1, JSON_UNESCAPED_SLASHES),
            json_encode($pass2, JSON_UNESCAPED_SLASHES),
            'Second normalize pass must produce identical result'
        );

        $this->assertSame(
            json_encode($pass2, JSON_UNESCAPED_SLASHES),
            json_encode($pass3, JSON_UNESCAPED_SLASHES),
            'Third normalize pass must produce identical result'
        );
    }

    /**
     * Nested objects get their keys sorted too.
     */
    public function testNestedObjectKeysSorted(): void
    {
        $writer = new OutputWriter($this->tmpDir);

        $data = [
            'z' => 1,
            'a' => ['z_inner' => 2, 'a_inner' => 1],
        ];

        $normalized = $writer->normalize($data);
        $keys = array_keys($normalized);
        $innerKeys = array_keys($normalized['a']);

        $this->assertSame(['a', 'z'], $keys, 'Top-level keys must be sorted');
        $this->assertSame(['a_inner', 'z_inner'], $innerKeys, 'Nested keys must be sorted');
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

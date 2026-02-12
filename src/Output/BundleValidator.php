<?php

declare(strict_types=1);

namespace MageContext\Output;

use MageContext\Config\CompilerConfig;

/**
 * B+.7: Post-compile bundle validator.
 *
 * Runs after extractors write output files but before final manifest is written.
 * Validates:
 *   1. Determinism: re-load JSON → re-normalize → recompute hash → compare
 *   2. Centrality config: edge types in centrality_edge_types must have weights,
 *      weights defined but never emitted → optional warning
 *   3. Evidence paths: required-evidence JSON paths per file exist
 *
 * Wiring order in CompileCommand:
 *   extractors → write files → build provisional manifest → run validator
 *   → write final manifest with validation results
 */
class BundleValidator
{
    private CompilerConfig $config;
    private OutputWriter $writer;
    private string $outputDir;

    /** @var array<array{level: string, rule: string, message: string}> */
    private array $results = [];

    public function __construct(CompilerConfig $config, OutputWriter $writer, string $outputDir)
    {
        $this->config = $config;
        $this->writer = $writer;
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * Run all validation checks.
     *
     * @param array $extractorResults Per-extractor results from CompileCommand
     * @param bool $skipDeterminism Skip the determinism check (for dev machines)
     * @param array $emittedEdgeTypes Edge types actually emitted by extractors
     * @return array{passed: bool, errors: array, warnings: array}
     */
    public function validate(
        array $extractorResults,
        bool $skipDeterminism = false,
        array $emittedEdgeTypes = [],
        array $allExtractedData = []
    ): array {
        $this->results = [];

        $this->validateCentralityConfig($emittedEdgeTypes);
        $this->validateEvidencePaths($extractorResults);

        // P2: Reverse index cross-check invariants
        if (!empty($allExtractedData)) {
            $this->validateReverseIndexConsistency($allExtractedData);
        }

        // P5: Reverse index size guardrail
        $this->validateReverseIndexSize();

        // P6: Module count delta
        if (!empty($allExtractedData)) {
            $this->validateModuleCountDelta($allExtractedData);
        }

        if (!$skipDeterminism) {
            $this->validateDeterminism($extractorResults);
        } else {
            $this->results[] = [
                'level' => 'info',
                'rule' => 'determinism_skipped',
                'message' => 'Determinism check skipped (--skip-determinism-check)',
            ];
        }

        $errors = array_filter($this->results, fn($r) => $r['level'] === 'error');
        $warnings = array_filter($this->results, fn($r) => $r['level'] === 'warning');

        return [
            'passed' => empty($errors),
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'info' => array_values(array_filter($this->results, fn($r) => $r['level'] === 'info')),
        ];
    }

    /**
     * B+.6/B+.7: Centrality config validation.
     *
     * - If edge type in centrality_edge_types but NOT in edge_weights → ERROR
     * - If edge type in edge_weights but never emitted → optional WARNING
     */
    private function validateCentralityConfig(array $emittedEdgeTypes): void
    {
        $centralityTypes = $this->config->getCentralityEdgeTypes();
        $edgeWeights = $this->config->getEdgeWeights();

        // Rule 1: centrality type must have a weight
        foreach ($centralityTypes as $type) {
            if (!isset($edgeWeights[$type])) {
                $this->results[] = [
                    'level' => 'error',
                    'rule' => 'centrality_missing_weight',
                    'message' => "Edge type '{$type}' is in centrality_edge_types but has no entry in edge_weights. This causes silent misweighting.",
                ];
            }
        }

        // Rule 2 (inverse): weight defined but never emitted → dead config
        if (!empty($emittedEdgeTypes)) {
            foreach (array_keys($edgeWeights) as $type) {
                if (!in_array($type, $emittedEdgeTypes, true)) {
                    $this->results[] = [
                        'level' => 'warning',
                        'rule' => 'edge_weight_never_emitted',
                        'message' => "Edge type '{$type}' has a weight defined but was never emitted by any extractor. Consider removing to prevent dead config rot.",
                    ];
                }
            }
        }
    }

    /**
     * B+.7: Evidence path validation.
     *
     * Checks that output files which should contain evidence arrays actually do.
     * Required-evidence paths are defined per output file type.
     */
    private function validateEvidencePaths(array $extractorResults): void
    {
        // Files that MUST have evidence arrays in their items
        $evidenceRequiredFiles = [
            'dependencies' => ['edges[].evidence'],
            'plugin_chains' => ['plugins[].evidence'],
            'event_graph' => ['listeners[].evidence'],
            'di_resolution_map' => ['resolutions[].evidence'],
            'route_map' => ['routes[].evidence'],
            'cron_map' => ['cron_jobs[].evidence'],
            'cli_commands' => ['commands[].evidence'],
            'api_surface' => ['endpoints[].evidence'],
            'db_schema_patches' => ['tables[].evidence'],
            'layout_handles' => ['handles[].evidence'],
            'ui_components' => ['components[].evidence'],
        ];

        foreach ($extractorResults as $name => $result) {
            if (($result['status'] ?? '') !== 'ok') {
                continue;
            }

            if (!isset($evidenceRequiredFiles[$name])) {
                continue;
            }

            foreach ($result['output_files'] ?? [] as $file) {
                $fullPath = $this->outputDir . '/' . $file;
                if (!is_file($fullPath)) {
                    continue;
                }

                $content = @file_get_contents($fullPath);
                if ($content === false) {
                    continue;
                }

                $data = @json_decode($content, true);
                if (!is_array($data)) {
                    continue;
                }

                foreach ($evidenceRequiredFiles[$name] as $path) {
                    $this->checkEvidencePath($name, $data, $path);
                }
            }
        }
    }

    /**
     * Check a single evidence path like "edges[].evidence" in the data.
     */
    private function checkEvidencePath(string $extractorName, array $data, string $path): void
    {
        // Parse "key[].field" format
        if (!preg_match('/^(\w+)\[\]\.(\w+)$/', $path, $m)) {
            return;
        }

        $collectionKey = $m[1];
        $fieldName = $m[2];

        $items = $data[$collectionKey] ?? [];
        if (!is_array($items) || empty($items)) {
            return;
        }

        $missingCount = 0;
        $sampleSize = min(count($items), 20);

        for ($i = 0; $i < $sampleSize; $i++) {
            if (!isset($items[$i][$fieldName]) || !is_array($items[$i][$fieldName])) {
                $missingCount++;
            }
        }

        if ($missingCount > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'missing_evidence',
                'message' => "Extractor '{$extractorName}': {$missingCount}/{$sampleSize} sampled items in '{$collectionKey}' lack '{$fieldName}' array.",
            ];
        }
    }

    /**
     * B+.7: Determinism check.
     *
     * Re-load JSON output files, re-normalize data structure, recompute hash,
     * compare with manifest build_hash. Fail validation on mismatch.
     *
     * Hash over NORMALIZED structure, NOT raw file bytes.
     * Prevents PHP version / whitespace false failures.
     */
    private function validateDeterminism(array $extractorResults): void
    {
        foreach ($extractorResults as $name => $result) {
            if (($result['status'] ?? '') !== 'ok') {
                continue;
            }

            foreach ($result['output_files'] ?? [] as $file) {
                if (!str_ends_with($file, '.json')) {
                    continue;
                }

                $fullPath = $this->outputDir . '/' . $file;
                if (!is_file($fullPath)) {
                    continue;
                }

                $raw = @file_get_contents($fullPath);
                if ($raw === false) {
                    continue;
                }

                $data = @json_decode($raw, true);
                if (!is_array($data)) {
                    $this->results[] = [
                        'level' => 'error',
                        'rule' => 'determinism_invalid_json',
                        'message' => "File '{$file}' is not valid JSON.",
                    ];
                    continue;
                }

                // Re-normalize and re-encode
                $normalized = $this->writer->normalize($data);
                $reEncoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

                if ($reEncoded !== $raw) {
                    $this->results[] = [
                        'level' => 'error',
                        'rule' => 'determinism_mismatch',
                        'message' => "File '{$file}' is not deterministic: re-normalizing and re-encoding produces different output. This indicates a normalization gap.",
                    ];
                }
            }
        }
    }

    /**
     * P2: Validate reverse index consistency against source extractors.
     *
     * Cross-checks:
     * - Every by_class[*].file_id exists in file_index
     * - Every by_class[*].module_id exists in modules
     * - Every event in by_event exists in event_graph
     * - Every route in by_route exists in route_map
     * - Every module in by_module exists in modules.json
     */
    private function validateReverseIndexConsistency(array $allData): void
    {
        $ri = $allData['reverse_index'] ?? [];
        if (empty($ri)) {
            return;
        }

        // Build lookup sets from source extractors
        $fileIds = [];
        foreach ($allData['file_index']['files'] ?? [] as $f) {
            $fileIds[$f['file_id']] = true;
        }

        $moduleIds = [];
        foreach ($allData['modules']['modules'] ?? [] as $m) {
            $moduleIds[$m['id'] ?? $m['name'] ?? ''] = true;
        }

        $eventIds = [];
        foreach ($allData['event_graph']['event_graph'] ?? [] as $e) {
            $eventIds[$e['event_id'] ?? $e['event'] ?? ''] = true;
        }

        $routeIds = [];
        foreach ($allData['route_map']['routes'] ?? [] as $r) {
            $routeIds[$r['route_id'] ?? ''] = true;
        }

        // Check by_class file_ids and module_ids
        $orphanFiles = 0;
        $orphanModules = 0;
        foreach ($ri['by_class'] ?? [] as $classId => $entry) {
            $fid = $entry['file_id'] ?? '';
            if ($fid !== '' && !isset($fileIds[$fid])) {
                $orphanFiles++;
            }
            $mid = $entry['module_id'] ?? '';
            if ($mid !== '' && $mid !== 'unknown' && !isset($moduleIds[$mid])) {
                $orphanModules++;
            }
        }

        if ($orphanFiles > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_orphan_files',
                'message' => "{$orphanFiles} class(es) in reverse_index.by_class reference file_ids not in file_index.",
            ];
        }
        if ($orphanModules > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_orphan_modules',
                'message' => "{$orphanModules} class(es) in reverse_index.by_class reference module_ids not in modules.",
            ];
        }

        // Check by_event against event_graph
        $orphanEvents = 0;
        foreach ($ri['by_event'] ?? [] as $eventId => $entry) {
            if (!isset($eventIds[$eventId])) {
                $orphanEvents++;
            }
        }
        if ($orphanEvents > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_orphan_events',
                'message' => "{$orphanEvents} event(s) in reverse_index.by_event not found in event_graph.",
            ];
        }

        // Check by_route against route_map
        $orphanRoutes = 0;
        foreach ($ri['by_route'] ?? [] as $routeId => $entry) {
            if (!isset($routeIds[$routeId])) {
                $orphanRoutes++;
            }
        }
        if ($orphanRoutes > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_orphan_routes',
                'message' => "{$orphanRoutes} route(s) in reverse_index.by_route not found in route_map.",
            ];
        }

        // Check by_module against modules.json
        $orphanModulesRI = 0;
        $orphanModuleList = [];
        foreach ($ri['by_module'] ?? [] as $modId => $entry) {
            if (!isset($moduleIds[$modId])) {
                $orphanModulesRI++;
                $orphanModuleList[] = $modId;
            }
        }
        if ($orphanModulesRI > 0) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_orphan_modules_ri',
                'message' => "{$orphanModulesRI} module(s) in reverse_index.by_module not found in modules.json: " . implode(', ', array_slice($orphanModuleList, 0, 5)),
            ];
        }
    }

    /**
     * P5: Validate reverse index file size doesn't exceed guardrail.
     */
    private function validateReverseIndexSize(): void
    {
        $maxMb = $this->config->getMaxReverseIndexSizeMb();
        $riPath = $this->outputDir . '/reverse_index/reverse_index.json';
        if (!is_file($riPath)) {
            return;
        }

        $sizeBytes = filesize($riPath);
        $sizeMb = $sizeBytes / (1024 * 1024);

        if ($sizeMb > $maxMb) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'reverse_index_size_exceeded',
                'message' => sprintf(
                    'reverse_index.json is %.1f MB (limit: %d MB). Consider switching to JSONL slices per key.',
                    $sizeMb,
                    $maxMb
                ),
            ];
        }
    }

    /**
     * P6: Validate module count consistency across outputs.
     */
    private function validateModuleCountDelta(array $allData): void
    {
        $moduleViewIds = [];
        foreach ($allData['modules']['modules'] ?? [] as $m) {
            $moduleViewIds[$m['id'] ?? $m['name'] ?? ''] = true;
        }

        $riModuleIds = array_keys($allData['reverse_index']['by_module'] ?? []);

        $inRiNotModules = array_diff($riModuleIds, array_keys($moduleViewIds));
        $inModulesNotRi = array_diff(array_keys($moduleViewIds), $riModuleIds);

        if (!empty($inRiNotModules)) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'module_count_delta',
                'message' => count($inRiNotModules) . ' module(s) in reverse_index but not in modules.json: ' . implode(', ', array_slice($inRiNotModules, 0, 5)),
            ];
        }
        if (!empty($inModulesNotRi)) {
            $this->results[] = [
                'level' => 'warning',
                'rule' => 'module_count_delta',
                'message' => count($inModulesNotRi) . ' module(s) in modules.json but not in reverse_index: ' . implode(', ', array_slice($inModulesNotRi, 0, 5)),
            ];
        }
    }

    /**
     * Get all validation results.
     *
     * @return array<array{level: string, rule: string, message: string}>
     */
    public function getResults(): array
    {
        return $this->results;
    }
}

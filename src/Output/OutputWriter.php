<?php

declare(strict_types=1);

namespace MageContext\Output;

use MageContext\Util\ArrayUtil;

use MageContext\Application;
use MageContext\Config\Schema;
use MageContext\Identity\WarningCollector;

class OutputWriter
{
    private string $outputDir;
    private string $repoPath;
    private string $repoCommit;
    private array $scopes;

    public function __construct(string $outputDir, string $repoPath = '', array $scopes = [])
    {
        $this->outputDir = rtrim($outputDir, '/');
        $this->repoPath = $repoPath;
        $this->scopes = $scopes;
        $this->repoCommit = $this->detectRepoCommit();
    }

    /**
     * Ensure the output directory structure exists.
     */
    public function prepare(): void
    {
        $dirs = [$this->outputDir];
        foreach (Schema::viewDirectories() as $view) {
            $dirs[] = $this->outputDir . '/' . $view;
        }

        // v2.1 additional directories
        $dirs[] = $this->outputDir . '/indexes';
        $dirs[] = $this->outputDir . '/reverse_index';
        $dirs[] = $this->outputDir . '/schemas';

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Write structured data as JSON with per-file metadata header.
     *
     * Spec \u00a71.3: Each JSON file includes $schema, generated_at, repo_commit, scope.
     */
    public function writeJson(string $relativePath, array $data, bool $addMetadata = true): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));

        if ($addMetadata && $relativePath !== 'manifest.json') {
            $schemaName = pathinfo($relativePath, PATHINFO_FILENAME);
            $data = array_merge([
                '\$schema' => 'schemas/' . $schemaName . '.schema.json',
                'generated_at' => date('c'),
                'repo_commit' => $this->repoCommit,
                'scope' => $this->scopes,
            ], $data);
        }

        // B+.4: Normalize for deterministic output before encoding
        $data = $this->normalize($data);

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Write structured data as JSONL (one JSON object per line).
     */
    public function writeJsonl(string $relativePath, array $records): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));
        $handle = fopen($path, 'w');

        foreach ($records as $record) {
            fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
        }

        fclose($handle);
    }

    /**
     * Write markdown content.
     */
    public function writeMarkdown(string $relativePath, string $content): void
    {
        $path = $this->outputDir . '/' . $relativePath;
        $this->ensureDir(dirname($path));
        file_put_contents($path, $content);
    }

    /**
     * Write the top-level manifest.json with enriched metadata.
     *
     * Spec \u00a71.3: compiler_version, schema_version, repo_commit, generated_at,
     * duration, scopes, extractor list with status/warnings/counts, build_hash.
     */
    public function writeManifest(array $extractorResults, float $duration, string $repoPath = '', array $scopes = [], string $target = '', ?WarningCollector $warningCollector = null, ?\MageContext\Config\CompilerConfig $config = null): void
    {
        $manifest = [
            'compiler_version' => Application::APP_VERSION,
            'schema_version' => Schema::VERSION,
            'repo_commit' => $this->repoCommit,
            'generated_at' => date('c'),
            'duration_seconds' => round($duration, 2),
            'repo_path' => $repoPath,
            'scopes' => $scopes,
            'target' => $target,
            'views' => [],
            'extractors' => [],
            'warnings' => [],
            'files' => [],
        ];

        $allWarnings = [];

        foreach ($extractorResults as $name => $result) {
            $view = $result['view'] ?? '.';
            $extractorEntry = [
                'name' => $name,
                'view' => $view,
                'status' => $result['status'] ?? 'ok',
                'item_count' => $result['item_count'] ?? 0,
                'duration_ms' => $result['duration_ms'] ?? null,
            ];

            // Include reason for skipped extractors
            if (!empty($result['reason'])) {
                $extractorEntry['reason'] = $result['reason'];
            }

            // Include per-extractor warnings (typed: category + message)
            if (!empty($result['warnings'])) {
                $extractorEntry['warnings'] = $result['warnings'];
                foreach ($result['warnings'] as $w) {
                    $allWarnings[] = [
                        'extractor' => $name,
                        'category' => $w['category'] ?? 'general',
                        'message' => $w['message'] ?? (is_string($w) ? $w : ''),
                    ];
                }
            }

            if (!empty($result['error'])) {
                $extractorEntry['error'] = $result['error'];
            }

            $manifest['extractors'][] = $extractorEntry;

            if (!empty($result['output_files'])) {
                foreach ($result['output_files'] as $file) {
                    $manifest['files'][] = $file;
                    $manifest['views'][$view][] = $file;
                }
            }
        }

        $manifest['warnings'] = $allWarnings;

        // B+.1: warnings_summary with analysis integrity score
        if ($warningCollector !== null) {
            $manifest['warnings_summary'] = $warningCollector->buildSummary();
        }

        // B+.7: Include validation results if present
        if (isset($extractorResults['_validation']['validation'])) {
            $manifest['validation'] = $extractorResults['_validation']['validation'];
            // Remove the synthetic _validation entry from extractors list
            $manifest['extractors'] = array_values(array_filter(
                $manifest['extractors'],
                fn($e) => ($e['name'] ?? '') !== '_validation'
            ));
        }

        // B+.8: Capabilities section — tells AI consumers what the bundle includes
        $manifest['capabilities'] = $this->buildCapabilities($extractorResults);

        // Churn configuration — tells consumers whether churn was active
        if ($config !== null) {
            $manifest['churn_config'] = [
                'enabled' => $config->isChurnEnabled(),
                'window_days' => $config->getChurnWindowDays(),
                'cache' => $config->isChurnCacheEnabled(),
            ];
        }

        // Deterministic build hash (for diffing)
        $hashInput = json_encode([
            $this->repoCommit,
            $scopes,
            $target,
            array_column($manifest['extractors'], 'name'),
            array_column($manifest['extractors'], 'item_count'),
        ], JSON_UNESCAPED_SLASHES);
        $manifest['build_hash'] = substr(hash('sha256', $hashInput), 0, 16);

        $this->writeJson('manifest.json', $manifest, false);
    }

    /**
     * Get the detected repo commit SHA.
     */
    public function getRepoCommit(): string
    {
        return $this->repoCommit;
    }

    /**
     * Detect the current git commit SHA of the repository.
     */
    private function detectRepoCommit(): string
    {
        if ($this->repoPath === '' || !is_dir($this->repoPath . '/.git')) {
            return 'unknown';
        }

        $headFile = $this->repoPath . '/.git/HEAD';
        if (!is_file($headFile)) {
            return 'unknown';
        }

        $head = trim(file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refPath = $this->repoPath . '/.git/' . substr($head, 5);
            if (is_file($refPath)) {
                return trim(file_get_contents($refPath));
            }
            return 'unknown';
        }

        // Detached HEAD — already a SHA
        return $head;
    }

    /**
     * B+.8: Build capabilities section for manifest.
     *
     * Derived from which extractors ran successfully.
     * Tells AI consumers what data is available without reading every file.
     */
    private function buildCapabilities(array $extractorResults): array
    {
        // Map extractor names to capability flags
        $capabilityMap = [
            'module_graph' => 'module_dependency_graph',
            'dependency_graph' => 'class_dependency_graph',
            'plugin_chains' => 'plugin_interception',
            'observer_map' => 'event_observer_map',
            'di_resolution_map' => 'di_resolution',
            'route_map' => 'route_mapping',
            'cron_map' => 'cron_scheduling',
            'cli_commands' => 'cli_commands',
            'api_surface' => 'api_surface',
            'db_schema' => 'database_schema',
            'layout_map' => 'layout_customization',
            'ui_components' => 'ui_components',
            'git_churn' => 'git_churn_analysis',
            'hotspot_ranking' => 'hotspot_ranking',
            'layer_classification' => 'layer_classification',
            'modifiability_score' => 'modifiability_scoring',
            'performance_indicators' => 'performance_indicators',
            'architectural_debt' => 'architectural_debt',
            'custom_deviations' => 'deviation_detection',
            'execution_paths' => 'execution_path_tracing',
            'repo_map' => 'repository_map',
        ];

        $capabilities = [];
        foreach ($extractorResults as $name => $result) {
            if (str_starts_with($name, '_') || str_starts_with($name, 'scenario_')) {
                continue;
            }
            $capName = $capabilityMap[$name] ?? $name;
            $capabilities[$capName] = ($result['status'] ?? '') === 'ok';
        }

        // Add meta-capabilities
        $capabilities['scenarios'] = !empty(array_filter(
            array_keys($extractorResults),
            fn($k) => str_starts_with($k, 'scenario_')
        ));
        $capabilities['ai_digest'] = is_file($this->outputDir . '/ai_digest.md');

        return $capabilities;
    }

    /**
     * B+.4: Normalize data for deterministic output.
     *
     * Schema-aware recursive pass:
     * - ksort all associative arrays (handles key ordering)
     * - Custom sort for known array-of-object collections by explicit sort keys
     *
     * Frozen sort keys:
     *   edges[]     → (from, to, edge_type)
     *   plugins[]   → (sort_order, plugin_class, type, subject_method)
     *   listeners[] → (event_id, module_id, observer_class)
     *   modules[]   → (module_id)
     *   routes[]    → (route_id)
     *   resolutions[] → (for)
     *   cron_jobs[] → (cron_id)
     *   commands[]  → (command_name)
     *   endpoints[] → (method, path)
     *   tables[]    → (table_name)
     *   deviations[] → (source_file, type)
     *   indicators[] → (module_id, type)
     *   debt_items[] → (module_id, type)
     *   handles[]   → (handle_name, area)
     *   components[] → (name, area)
     */
    public function normalize(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        // Check if this is an associative array (object) or sequential (list)
        if (ArrayUtil::isAssoc($data)) {
            // Sort known list keys by their explicit sort fields
            foreach ($data as $key => $value) {
                $data[$key] = $this->normalize($value);
            }
            ksort($data);
            return $data;
        }

        // Sequential array — recurse into items first
        $data = array_map(fn($item) => $this->normalize($item), $data);

        // If items are arrays (objects), try schema-aware sorting
        if (!empty($data) && is_array($data[0])) {
            $sortKeys = $this->inferSortKeys($data[0]);
            if ($sortKeys !== null) {
                usort($data, function ($a, $b) use ($sortKeys) {
                    foreach ($sortKeys as $key) {
                        $va = $a[$key] ?? '';
                        $vb = $b[$key] ?? '';
                        // Handle array values (e.g. from/to: {kind, id}) by serializing
                        if (is_array($va)) {
                            $va = json_encode($va, JSON_UNESCAPED_SLASHES);
                        }
                        if (is_array($vb)) {
                            $vb = json_encode($vb, JSON_UNESCAPED_SLASHES);
                        }
                        $cmp = is_numeric($va) && is_numeric($vb)
                            ? ($va <=> $vb)
                            : strcmp((string) $va, (string) $vb);
                        if ($cmp !== 0) {
                            return $cmp;
                        }
                    }
                    return 0;
                });
            }
        }

        return $data;
    }

    /**
     * Infer sort keys from the shape of array items.
     * Uses presence of known field names to identify collection type.
     *
     * @return string[]|null Sort key fields, or null if unknown shape
     */
    private function inferSortKeys(array $sampleItem): ?array
    {
        $sortMap = [
            // Edges: dependency graph, module graph
            ['keys' => ['from', 'to', 'edge_type'], 'sort' => ['from', 'to', 'edge_type']],
            // Plugins
            ['keys' => ['plugin_class', 'subject_method'], 'sort' => ['sort_order', 'plugin_class', 'type', 'subject_method']],
            // Observers/listeners
            ['keys' => ['event_id', 'observer_class'], 'sort' => ['event_id', 'module_id', 'observer_class']],
            // Modules
            ['keys' => ['module_id', 'has_module_xml'], 'sort' => ['module_id']],
            // Routes
            ['keys' => ['route_id', 'area'], 'sort' => ['route_id']],
            // DI resolutions
            ['keys' => ['for', 'resolved_to'], 'sort' => ['for']],
            // Cron jobs
            ['keys' => ['cron_id', 'group'], 'sort' => ['cron_id']],
            // CLI commands
            ['keys' => ['command_name'], 'sort' => ['command_name']],
            // API endpoints
            ['keys' => ['method', 'path', 'service_class'], 'sort' => ['method', 'path']],
            // DB tables
            ['keys' => ['table_name', 'declared_by'], 'sort' => ['table_name']],
            // Deviations
            ['keys' => ['source_file', 'type', 'severity'], 'sort' => ['source_file', 'type']],
            // Performance indicators
            ['keys' => ['module_id', 'type', 'why_risky'], 'sort' => ['module_id', 'type']],
            // Architectural debt
            ['keys' => ['module_id', 'type', 'why_risky'], 'sort' => ['module_id', 'type']],
            // Layout handles
            ['keys' => ['handle_name', 'area', 'declared_by'], 'sort' => ['handle_name', 'area']],
            // UI components
            ['keys' => ['name', 'area', 'type'], 'sort' => ['name', 'area']],
        ];

        foreach ($sortMap as $rule) {
            $allPresent = true;
            foreach ($rule['keys'] as $k) {
                if (!array_key_exists($k, $sampleItem)) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                // Return only sort keys that exist in the item
                return array_filter($rule['sort'], fn($k) => array_key_exists($k, $sampleItem));
            }
        }

        return null;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

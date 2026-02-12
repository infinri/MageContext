<?php

declare(strict_types=1);

namespace MageContext\Config;

/**
 * Loads .context-compiler.json and provides merged configuration.
 *
 * Spec §9: Config file with scope paths, edge types, thresholds,
 * include_vendor, max_evidence_per_edge.
 *
 * CLI options override config file values.
 */
class CompilerConfig
{
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Load config from .context-compiler.json in the repo root, merged with defaults.
     */
    public static function load(string $repoPath, array $cliOverrides = []): self
    {
        $defaults = self::defaults();
        $fileConfig = self::loadFile($repoPath);

        // Merge: defaults < file config < CLI overrides
        $merged = self::merge($defaults, $fileConfig);
        $merged = self::merge($merged, $cliOverrides);

        return new self($merged);
    }

    /**
     * Default configuration values.
     */
    public static function defaults(): array
    {
        return [
            'scopes' => ['app/code', 'app/design'],
            'include_vendor' => false,
            'output_dir' => '.ai-context',
            'output_format' => 'json',

            'edge_types' => [
                'module_sequence',
                'composer_require',
                'php_symbol_use',
                'di_preference',
                'di_virtual_type',
                'plugin_intercept',
                'event_observe',
                'route_entry',
                'layout_handle',
                'ui_component',
                'api_contract',
                'db_patch',
            ],

            'thresholds' => [
                'plugin_depth' => 5,
                'event_fanout' => 10,
                'churn_window_days' => 365,
                'max_violations' => null,
                'max_cycles' => null,
                'max_deviations' => null,
                'max_risk' => null,
            ],

            'max_evidence_per_edge' => 5,

            'coupling_metric_subsets' => [
                'structural' => ['module_sequence', 'composer_require'],
                'code' => ['php_symbol_use'],
                'runtime' => ['di_preference', 'plugin_intercept', 'event_observe'],
            ],

            // B+.6: Centrality configuration
            'edge_weights' => [
                'module_sequence' => 0.7,
                'composer_require' => 0.6,
                'di_preference' => 1.0,
                'plugin_intercept' => 1.2,
                'event_observe' => 1.1,
                'route_entry' => 1.0,
                'api_contract' => 1.1,
                'layout_handle' => 0.9,
            ],

            // Churn configuration: controls git churn analysis behavior
            // CI uses full 365. Local dev can use 30 or disable.
            'churn' => [
                'enabled' => true,
                'window_days' => 365,
                'cache' => true,
            ],

            // P5: Max reverse index size before validator warns (MB)
            'max_reverse_index_size_mb' => 10,

            // Explicit list — new edge types must be added here to enter centrality.
            // Excludes php_symbol_use (noisy). Available as separate "code centrality" if needed.
            'centrality_edge_types' => [
                'module_sequence',
                'composer_require',
                'di_preference',
                'plugin_intercept',
                'event_observe',
                'route_entry',
                'api_contract',
                'layout_handle',
            ],
        ];
    }

    /**
     * Load config from .context-compiler.json file.
     */
    private static function loadFile(string $repoPath): array
    {
        $configPath = rtrim($repoPath, '/') . '/.context-compiler.json';
        if (!is_file($configPath)) {
            return [];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return [];
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            return [];
        }

        return $parsed;
    }

    /**
     * Deep merge two arrays. Second array values override first.
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    // --- Accessors ---

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getScopes(): array
    {
        return $this->config['scopes'] ?? ['app/code', 'app/design'];
    }

    public function includeVendor(): bool
    {
        return $this->config['include_vendor'] ?? false;
    }

    public function getOutputDir(): string
    {
        return $this->config['output_dir'] ?? '.ai-context';
    }

    public function getOutputFormat(): string
    {
        return $this->config['output_format'] ?? 'json';
    }

    public function getEnabledEdgeTypes(): array
    {
        return $this->config['edge_types'] ?? [];
    }

    public function getThreshold(string $name): mixed
    {
        return $this->config['thresholds'][$name] ?? null;
    }

    public function getMaxEvidencePerEdge(): int
    {
        return $this->config['max_evidence_per_edge'] ?? 5;
    }

    public function getCouplingMetricSubsets(): array
    {
        return $this->config['coupling_metric_subsets'] ?? [];
    }

    public function getChurnWindowDays(): int
    {
        return $this->config['churn']['window_days']
            ?? $this->config['thresholds']['churn_window_days']
            ?? 365;
    }

    public function isChurnEnabled(): bool
    {
        return $this->config['churn']['enabled'] ?? true;
    }

    public function isChurnCacheEnabled(): bool
    {
        return $this->config['churn']['cache'] ?? true;
    }

    /**
     * B+.6: Get edge weight for a given edge type.
     * Returns 1.0 for unknown types (safe default).
     */
    public function getEdgeWeight(string $edgeType): float
    {
        return (float) ($this->config['edge_weights'][$edgeType] ?? 1.0);
    }

    /**
     * B+.6: Get all configured edge weights.
     *
     * @return array<string, float>
     */
    public function getEdgeWeights(): array
    {
        return $this->config['edge_weights'] ?? [];
    }

    /**
     * B+.6: Get the explicit list of edge types used for centrality computation.
     * Only these types enter the centrality graph. New types must be added explicitly.
     *
     * @return string[]
     */
    public function getCentralityEdgeTypes(): array
    {
        return $this->config['centrality_edge_types'] ?? [];
    }

    /**
     * P5: Maximum reverse index file size in MB before validator warns.
     */
    public function getMaxReverseIndexSizeMb(): int
    {
        return (int) ($this->config['max_reverse_index_size_mb'] ?? 10);
    }

    /**
     * Get the full config array (for serialization/debugging).
     */
    public function toArray(): array
    {
        return $this->config;
    }
}

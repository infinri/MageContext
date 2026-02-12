<?php

declare(strict_types=1);

namespace MageContext\Output;

use MageContext\Config\Schema;

/**
 * D.1: Generates JSON Schema files for all output artifacts.
 *
 * Schemas serve as:
 * - Documentation for AI consumers
 * - Validation contracts for downstream tools
 * - Self-describing output (each JSON file references its schema via $schema)
 *
 * Output: schemas/ directory with one .schema.json per output file type.
 */
class SchemaGenerator
{
    /**
     * Generate all schemas and write them to the output directory.
     *
     * @param OutputWriter $writer
     * @return array<string> List of schema files written
     */
    public function generate(OutputWriter $writer): array
    {
        $schemas = $this->buildSchemas();
        $files = [];

        foreach ($schemas as $name => $schema) {
            $file = "schemas/{$name}.schema.json";
            $writer->writeJson($file, $schema);
            $files[] = $file;
        }

        return $files;
    }

    /**
     * Build all schema definitions.
     *
     * @return array<string, array>
     */
    private function buildSchemas(): array
    {
        return [
            'manifest' => $this->manifestSchema(),
            'symbol_index' => $this->symbolIndexSchema(),
            'file_index' => $this->fileIndexSchema(),
            'reverse_index' => $this->reverseIndexSchema(),
            'modules' => $this->modulesSchema(),
            'dependencies' => $this->dependenciesSchema(),
            'plugin_chains' => $this->pluginChainsSchema(),
            'event_graph' => $this->eventGraphSchema(),
            'di_resolution_map' => $this->diResolutionSchema(),
            'route_map' => $this->routeMapSchema(),
            'cron_map' => $this->cronMapSchema(),
            'execution_paths' => $this->executionPathsSchema(),
            'hotspot_ranking' => $this->hotspotRankingSchema(),
            'modifiability' => $this->modifiabilitySchema(),
            'areas' => $this->areasSchema(),
            'scenario_coverage' => $this->scenarioCoverageSchema(),
            'scenario_bundle' => $this->scenarioBundleSchema(),
        ];
    }

    private function manifestSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Context Compiler Manifest',
            'description' => 'Build manifest with extractor results, capabilities, and validation status.',
            'type' => 'object',
            'required' => ['compiler_version', 'generated_at', 'repo_path', 'target', 'build_hash', 'extractors', 'capabilities'],
            'properties' => [
                'compiler_version' => ['type' => 'string'],
                'generated_at' => ['type' => 'string', 'format' => 'date-time'],
                'duration_seconds' => ['type' => 'number'],
                'repo_path' => ['type' => 'string'],
                'repo_commit' => ['type' => 'string'],
                'target' => ['type' => 'string'],
                'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'build_hash' => ['type' => 'string'],
                'extractors' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['ok', 'error', 'skipped']],
                            'item_count' => ['type' => 'integer'],
                            'duration_ms' => ['type' => 'number'],
                            'view' => ['type' => 'string'],
                            'output_files' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'capabilities' => [
                    'type' => 'object',
                    'properties' => [
                        'has_symbol_index' => ['type' => 'boolean'],
                        'has_reverse_indexes' => ['type' => 'boolean'],
                        'has_scenarios' => ['type' => 'boolean'],
                        'has_allocation_view' => ['type' => 'boolean'],
                        'has_diff_support' => ['type' => 'boolean'],
                        'git_churn_hotspots' => ['type' => 'boolean'],
                        'hotspot_ranking' => ['type' => 'boolean'],
                    ],
                ],
                'churn_config' => [
                    'type' => 'object',
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'window_days' => ['type' => 'integer'],
                        'cache' => ['type' => 'boolean'],
                    ],
                ],
                'validation' => [
                    'type' => 'object',
                    'properties' => [
                        'passed' => ['type' => 'boolean'],
                        'errors' => ['type' => 'array'],
                        'warnings' => ['type' => 'array'],
                    ],
                ],
            ],
        ];
    }

    private function symbolIndexSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Symbol Index',
            'description' => 'Class→file→module mapping for O(1) lookups. Every PHP symbol (class, interface, trait, enum) in scope.',
            'type' => 'object',
            'required' => ['symbols', 'symbol_kinds', 'summary'],
            'properties' => [
                'symbols' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['class_id', 'fqcn', 'symbol_type', 'file_id', 'module_id'],
                        'properties' => [
                            'class_id' => ['type' => 'string', 'description' => 'Canonical FQCN (lowercase)'],
                            'fqcn' => ['type' => 'string', 'description' => 'Original FQCN'],
                            'symbol_type' => ['type' => 'string', 'enum' => ['class', 'interface', 'trait', 'enum']],
                            'file_id' => ['type' => 'string', 'description' => 'Relative path from repo root'],
                            'module_id' => ['type' => 'string', 'description' => 'Vendor_Module format'],
                            'extends' => ['type' => ['string', 'null', 'array']],
                            'implements' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'public_methods' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'is_abstract' => ['type' => 'boolean'],
                            'is_final' => ['type' => 'boolean'],
                            'line' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'symbol_kinds' => [
                    'type' => 'object',
                    'properties' => [
                        'emitted' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'supported' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'not_indexed' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'note' => ['type' => 'string'],
                    ],
                ],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'total_symbols' => ['type' => 'integer'],
                        'total_files_scanned' => ['type' => 'integer'],
                        'parse_errors' => ['type' => 'integer'],
                        'by_type' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
    }

    private function fileIndexSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'File Index',
            'description' => 'File→module→layer mapping for O(1) lookups.',
            'type' => 'object',
            'required' => ['files', 'summary'],
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['file_id', 'module_id', 'layer', 'file_type'],
                        'properties' => [
                            'file_id' => ['type' => 'string'],
                            'module_id' => ['type' => 'string'],
                            'layer' => ['type' => 'string', 'enum' => ['presentation', 'service', 'domain', 'infrastructure', 'framework', 'unknown']],
                            'file_type' => ['type' => 'string'],
                            'extension' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'total_files' => ['type' => 'integer'],
                        'by_type' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                        'by_layer' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
    }

    private function reverseIndexSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Reverse Index',
            'description' => 'Given X, find all facts about X. Keyed by class_id, module_id, event_id, route_id.',
            'type' => 'object',
            'required' => ['by_class', 'by_module', 'by_event', 'by_route', 'summary'],
            'properties' => [
                'by_class' => [
                    'type' => 'object',
                    'description' => 'class_id → {file_id, module_id, symbol_type, plugins_on[], di_resolutions[], events_observed[]}',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'class_id' => ['type' => 'string'],
                            'fqcn' => ['type' => 'string'],
                            'file_id' => ['type' => 'string'],
                            'module_id' => ['type' => 'string'],
                            'symbol_type' => ['type' => 'string'],
                            'plugins_on' => ['type' => 'array'],
                            'di_resolutions' => ['type' => 'array'],
                            'events_observed' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'by_module' => [
                    'type' => 'object',
                    'description' => 'module_id → {files[], classes[], plugins_declared[], events_observed[], routes[], crons[], debt_items[]}',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'module_id' => ['type' => 'string'],
                            'files' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'classes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'plugins_declared' => ['type' => 'array'],
                            'events_observed' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'routes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'crons' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'cli_commands' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'debt_items' => ['type' => 'array'],
                            'deviations' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'by_event' => [
                    'type' => 'object',
                    'description' => 'event_id → {observers[], cross_module_count, risk_score}',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'string'],
                            'observer_count' => ['type' => 'integer'],
                            'observers' => ['type' => 'array'],
                            'cross_module_count' => ['type' => 'integer'],
                            'risk_score' => ['type' => 'number'],
                        ],
                    ],
                ],
                'by_route' => [
                    'type' => 'object',
                    'description' => 'route_id → {area, method, path, controller, module_id, plugins_on_controller[]}',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'route_id' => ['type' => 'string'],
                            'area' => ['type' => 'string'],
                            'method' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                            'controller' => ['type' => 'string'],
                            'module_id' => ['type' => 'string'],
                            'plugins_on_controller' => ['type' => 'array'],
                        ],
                    ],
                ],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'indexed_classes' => ['type' => 'integer'],
                        'indexed_modules' => ['type' => 'integer'],
                        'indexed_events' => ['type' => 'integer'],
                        'indexed_routes' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    private function modulesSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Module Graph',
            'description' => 'Discovered modules, composer packages, themes with dependency edges.',
            'type' => 'object',
            'required' => ['modules', 'summary'],
            'properties' => [
                'modules' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'type', 'path'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'id' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['magento_module', 'composer_package', 'theme', 'library']],
                            'path' => ['type' => 'string'],
                            'enabled' => ['type' => 'boolean'],
                            'version' => ['type' => 'string'],
                            'sequence_dependencies' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'edges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                            'edge_type' => ['type' => 'string'],
                            'evidence' => ['type' => 'array'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function dependenciesSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Dependency Graph',
            'description' => 'Typed, directed dependency graph with evidence and split coupling metrics.',
            'type' => 'object',
            'required' => ['edges', 'coupling_metrics', 'summary'],
            'properties' => [
                'edges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['from', 'to', 'edge_type', 'weight'],
                        'properties' => [
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                            'edge_type' => ['type' => 'string', 'enum' => ['module_sequence', 'composer_require', 'php_symbol_use', 'di_preference', 'di_virtual_type', 'plugin_intercept', 'event_observe']],
                            'weight' => ['type' => 'integer'],
                            'evidence' => ['type' => 'array'],
                        ],
                    ],
                ],
                'coupling_metrics' => [
                    'type' => 'object',
                    'description' => 'Split coupling metrics: structural, code, runtime, composite',
                ],
                'analysis_integrity_score' => ['type' => 'number'],
                'degraded' => ['type' => 'boolean'],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function pluginChainsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Plugin Chains',
            'description' => 'Plugin chains per target class and method with evidence.',
            'type' => 'object',
            'required' => ['plugins', 'summary'],
            'properties' => [
                'plugins' => ['type' => 'array'],
                'class_chains' => ['type' => 'object'],
                'method_chains' => ['type' => 'object'],
                'deep_chains' => ['type' => 'array'],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function eventGraphSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Event Graph',
            'description' => 'Event/observer graph with cross-module risk scoring.',
            'type' => 'object',
            'required' => ['event_graph', 'observers', 'summary'],
            'properties' => [
                'event_graph' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'string'],
                            'event' => ['type' => 'string'],
                            'declared_by' => ['type' => 'string'],
                            'listeners' => ['type' => 'array'],
                            'listener_count' => ['type' => 'integer'],
                            'cross_module_listeners' => ['type' => 'integer'],
                            'risk_score' => ['type' => 'number'],
                        ],
                    ],
                ],
                'observers' => ['type' => 'array'],
                'high_risk_events' => ['type' => 'array'],
                'top_impacted_modules' => ['type' => 'array'],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function diResolutionSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'DI Resolution Map',
            'description' => 'Per-area DI resolution map with resolution chains and confidence.',
            'type' => 'object',
            'required' => ['resolutions', 'summary'],
            'properties' => [
                'resolutions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'interface' => ['type' => 'string'],
                            'final_resolved_type' => ['type' => 'string'],
                            'area' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                            'resolution_chain' => ['type' => 'array'],
                            'evidence' => ['type' => 'array'],
                        ],
                    ],
                ],
                'virtual_types' => ['type' => 'array'],
                'factories' => ['type' => 'array'],
                'proxies' => ['type' => 'array'],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function routeMapSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Route Map',
            'description' => 'Route declarations from routes.xml with evidence.',
            'type' => 'object',
            'required' => ['routes', 'summary'],
            'properties' => [
                'routes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'route_id' => ['type' => 'string'],
                            'area' => ['type' => 'string'],
                            'front_name' => ['type' => 'string'],
                            'router' => ['type' => 'string'],
                            'declared_by' => ['type' => 'string'],
                            'evidence' => ['type' => 'array'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function cronMapSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Cron Map',
            'description' => 'Cron job declarations from crontab.xml.',
            'type' => 'object',
            'required' => ['cron_jobs', 'summary'],
            'properties' => [
                'cron_jobs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cron_id' => ['type' => 'string'],
                            'job_name' => ['type' => 'string'],
                            'group' => ['type' => 'string'],
                            'instance' => ['type' => 'string'],
                            'method' => ['type' => 'string'],
                            'schedule' => ['type' => 'string'],
                            'declared_by' => ['type' => 'string'],
                            'evidence' => ['type' => 'array'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function executionPathsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Execution Paths',
            'description' => 'Reconstructed execution paths from entry points through DI, plugins, observers.',
            'type' => 'object',
            'required' => ['paths', 'summary'],
            'properties' => [
                'paths' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'scenario' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'area' => ['type' => 'string'],
                            'entry_point' => ['type' => 'string'],
                            'module' => ['type' => 'string'],
                            'plugin_stack' => ['type' => 'array'],
                            'plugin_depth' => ['type' => 'integer'],
                            'observer_count' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function hotspotRankingSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Hotspot Ranking',
            'description' => 'Modules ranked by combined git churn and dependency graph centrality.',
            'type' => 'object',
            'required' => ['rankings', 'summary'],
            'properties' => [
                'rankings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'module' => ['type' => 'string'],
                            'rank' => ['type' => 'integer'],
                            'final_score' => ['type' => 'number'],
                            'raw_score' => ['type' => 'number'],
                            'churn_score' => ['type' => 'number'],
                            'centrality_score' => ['type' => 'number'],
                            'integrity_score_used' => ['type' => 'number'],
                        ],
                    ],
                ],
                'analysis_integrity_score' => ['type' => 'number'],
                'degraded' => ['type' => 'boolean'],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function modifiabilitySchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Modifiability Risk',
            'description' => 'Per-module modifiability risk score from coupling, churn, plugin density, deviations.',
            'type' => 'object',
            'required' => ['modules', 'summary'],
            'properties' => [
                'modules' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'module' => ['type' => 'string'],
                            'modifiability_risk_score' => ['type' => 'number'],
                            'coupling_score' => ['type' => 'number'],
                            'churn_score' => ['type' => 'number'],
                            'plugin_density' => ['type' => 'number'],
                            'deviation_count' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'object'],
            ],
        ];
    }

    private function areasSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Area Allocation',
            'description' => 'Per-area module allocation: which modules are active in which Magento areas.',
            'type' => 'object',
            'required' => ['areas', 'module_areas', 'summary'],
            'properties' => [
                'areas' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'area' => ['type' => 'string'],
                            'modules' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'module_count' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'module_areas' => [
                    'type' => 'object',
                    'description' => 'module_id → [areas]',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'total_areas_active' => ['type' => 'integer'],
                        'total_module_area_mappings' => ['type' => 'integer'],
                        'multi_area_modules' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    private function scenarioCoverageSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Scenario Coverage',
            'description' => 'Scenario seed matching report: which execution paths matched canonical seeds, which did not, and why.',
            'type' => 'object',
            'required' => ['total_seeds', 'total_scenarios', 'matched', 'unmatched'],
            'properties' => [
                'total_seeds' => ['type' => 'integer', 'description' => 'Seeds from ScenarioSeedResolver (routes + crons + cli + api)'],
                'total_scenarios' => ['type' => 'integer', 'description' => 'Unique scenarios from execution paths'],
                'matched' => ['type' => 'integer', 'description' => 'Scenarios that matched a canonical seed'],
                'unmatched' => ['type' => 'integer', 'description' => 'Scenarios with no canonical seed match'],
                'unmatched_by_type' => [
                    'type' => 'object',
                    'description' => 'Unmatched count by entry type',
                    'additionalProperties' => ['type' => 'integer'],
                ],
                'unmatched_details' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'scenario_id' => ['type' => 'string'],
                            'scenario_name' => ['type' => 'string'],
                            'entry_type' => ['type' => 'string'],
                            'reason_code' => ['type' => 'string', 'enum' => ['no_matching_route_id', 'cron_class_not_in_crontab_xml', 'cli_class_not_in_di_xml', 'missing_entry_class', 'unsupported_entry_type']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function scenarioBundleSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Scenario Bundle',
            'description' => 'Self-contained slice for a single entry point: execution chain, affected modules, risk, QA concerns.',
            'type' => 'object',
            'required' => ['scenario', 'scenario_id', 'entry_point', 'execution_chain', 'affected_modules', 'risk'],
            'properties' => [
                'scenario' => ['type' => 'string'],
                'scenario_id' => ['type' => 'string', 'description' => 'Deterministic SHA1 from canonical entry'],
                'canonical_entry' => [
                    'type' => 'object',
                    'description' => 'Frozen-shape entry from ScenarioSeedResolver (present only if matched)',
                ],
                'entry_point' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'class' => ['type' => 'string'],
                        'module' => ['type' => 'string'],
                        'method' => ['type' => 'string'],
                        'step_kind' => ['type' => 'string', 'enum' => ['http_request', 'scheduled_task', 'cli_command', 'api_call', 'unknown']],
                    ],
                ],
                'execution_chain' => [
                    'type' => 'object',
                    'properties' => [
                        'di_resolution_chain' => ['type' => 'array'],
                        'plugin_stack' => ['type' => 'array'],
                        'observer_triggers' => ['type' => 'array'],
                        'complexity' => ['type' => 'object'],
                    ],
                ],
                'affected_modules' => ['type' => 'array', 'items' => ['type' => 'string']],
                'dependency_slice' => ['type' => 'array'],
                'module_refs' => [
                    'type' => 'object',
                    'description' => 'Per-module summary from reverse_index',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'class_count' => ['type' => 'integer'],
                            'route_count' => ['type' => 'integer'],
                            'debt_count' => ['type' => 'integer'],
                            'deviations' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'risk' => [
                    'type' => 'object',
                    'properties' => [
                        'level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'score' => ['type' => 'number'],
                        'reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'qa_concerns' => ['type' => 'array'],
            ],
        ];
    }
}

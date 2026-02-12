<?php

declare(strict_types=1);

namespace MageContext\Config;

/**
 * Defines the output schema version and structure constants.
 * All extractors and writers should reference these to ensure consistency.
 */
class Schema
{
    public const VERSION = '2.0.0';

    /**
     * Architectural view directories.
     */
    public const DIR_MODULE_VIEW = 'module_view';
    public const DIR_RUNTIME_VIEW = 'runtime_view';
    public const DIR_ALLOCATION_VIEW = 'allocation_view';
    public const DIR_QUALITY_METRICS = 'quality_metrics';
    public const DIR_SCENARIOS = 'scenarios';

    /**
     * Module view output files.
     */
    public const FILE_MODULES = self::DIR_MODULE_VIEW . '/modules';
    public const FILE_DEPENDENCIES = self::DIR_MODULE_VIEW . '/dependencies';
    public const FILE_COUPLING_METRICS = self::DIR_MODULE_VIEW . '/coupling_metrics';
    public const FILE_LAYER_CLASSIFICATION = self::DIR_MODULE_VIEW . '/layer_classification';
    public const FILE_LAYER_VIOLATIONS = self::DIR_MODULE_VIEW . '/layer_violations';
    public const FILE_LAYOUT = self::DIR_MODULE_VIEW . '/layout_handles';
    public const FILE_UI_COMPONENTS = self::DIR_MODULE_VIEW . '/ui_components';
    public const FILE_DB_SCHEMA = self::DIR_MODULE_VIEW . '/db_schema_patches';
    public const FILE_API_SURFACE = self::DIR_MODULE_VIEW . '/api_surface';

    /**
     * Runtime view output files.
     */
    public const FILE_EXECUTION_PATHS = self::DIR_RUNTIME_VIEW . '/execution_paths';
    public const FILE_PLUGIN_CHAINS = self::DIR_RUNTIME_VIEW . '/plugin_chains';
    public const FILE_EVENT_GRAPH = self::DIR_RUNTIME_VIEW . '/event_graph';
    public const FILE_DI_RESOLUTION = self::DIR_RUNTIME_VIEW . '/di_resolution_map';
    public const FILE_ROUTES = self::DIR_RUNTIME_VIEW . '/routes';

    /**
     * Allocation view output files.
     */
    public const FILE_SCOPE_CONFIG = self::DIR_ALLOCATION_VIEW . '/scope_config';
    public const FILE_CRON_JOBS = self::DIR_ALLOCATION_VIEW . '/cron_jobs';
    public const FILE_MESSAGE_QUEUES = self::DIR_ALLOCATION_VIEW . '/message_queues';

    /**
     * Quality metrics output files.
     */
    public const FILE_MODIFIABILITY = self::DIR_QUALITY_METRICS . '/modifiability';
    public const FILE_PERFORMANCE = self::DIR_QUALITY_METRICS . '/performance';
    public const FILE_ARCHITECTURAL_DEBT = self::DIR_QUALITY_METRICS . '/architectural_debt';
    public const FILE_HOTSPOT_RANKING = self::DIR_QUALITY_METRICS . '/hotspot_ranking';
    public const FILE_DEVIATIONS = self::DIR_QUALITY_METRICS . '/custom_deviations';

    /**
     * Top-level files.
     */
    public const FILE_MANIFEST = 'manifest.json';
    public const FILE_REPO_MAP = 'repo_map';
    public const FILE_AI_DIGEST = 'ai_digest.md';

    /**
     * Expected manifest.json structure.
     *
     * @return array<string, mixed>
     */
    public static function manifestTemplate(): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => '',
            'duration_seconds' => 0,
            'repo_path' => '',
            'scopes' => [],
            'target' => '',
            'views' => [
                'module_view' => [],
                'runtime_view' => [],
                'allocation_view' => [],
                'quality_metrics' => [],
                'scenarios' => [],
            ],
            'extractors' => [],
            'files' => [],
        ];
    }

    /**
     * All architectural view directories.
     *
     * @return array<string>
     */
    public static function viewDirectories(): array
    {
        return [
            self::DIR_MODULE_VIEW,
            self::DIR_RUNTIME_VIEW,
            self::DIR_ALLOCATION_VIEW,
            self::DIR_QUALITY_METRICS,
            self::DIR_SCENARIOS,
        ];
    }

    /**
     * Module graph node structure.
     *
     * @return array<string, string>
     */
    public static function moduleNodeSchema(): array
    {
        return [
            'id' => 'string — fully qualified module name (e.g., Vendor_Module)',
            'type' => 'string — magento_module|composer_package|theme|library',
            'path' => 'string — relative path from repo root',
            'area' => 'array<string> — frontend|adminhtml|global',
            'enabled' => 'bool — whether module is enabled',
            'version' => 'string — module version from module.xml',
            'dependencies' => 'array<string> — list of module names this depends on',
        ];
    }

    /**
     * DI preference entry structure.
     *
     * @return array<string, string>
     */
    public static function diPreferenceSchema(): array
    {
        return [
            'interface' => 'string — the interface/class being replaced',
            'preference' => 'string — the concrete class used instead',
            'scope' => 'string — global|frontend|adminhtml|webapi_rest|etc',
            'source_file' => 'string — path to the di.xml defining this',
            'is_core_override' => 'bool — true if preference replaces a Magento core class',
        ];
    }

    /**
     * Plugin/interceptor entry structure.
     *
     * @return array<string, string>
     */
    public static function pluginSchema(): array
    {
        return [
            'target_class' => 'string — the class being intercepted',
            'plugin_name' => 'string — plugin name attribute',
            'plugin_class' => 'string — the plugin implementation class',
            'sort_order' => 'int|null — execution order',
            'disabled' => 'bool — whether this plugin is disabled',
            'scope' => 'string — global|frontend|adminhtml|etc',
            'source_file' => 'string — path to the di.xml defining this',
            'methods' => 'array<string> — before/after/around methods found in plugin class',
        ];
    }

    /**
     * Observer entry structure.
     *
     * @return array<string, string>
     */
    public static function observerSchema(): array
    {
        return [
            'event_name' => 'string — the event being observed',
            'observer_name' => 'string — observer name attribute',
            'observer_class' => 'string — the observer implementation class',
            'scope' => 'string — global|frontend|adminhtml|etc',
            'source_file' => 'string — path to the events.xml defining this',
            'disabled' => 'bool — whether this observer is disabled',
        ];
    }

    /**
     * Git churn hotspot structure.
     *
     * @return array<string, string>
     */
    public static function hotspotSchema(): array
    {
        return [
            'file' => 'string — relative path from repo root',
            'change_count' => 'int — number of commits touching this file',
            'line_count' => 'int — current file length',
            'last_modified' => 'string — ISO date of most recent change',
            'score' => 'float — composite hotspot score (churn × size)',
        ];
    }
}

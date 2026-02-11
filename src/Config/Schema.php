<?php

declare(strict_types=1);

namespace MageContext\Config;

/**
 * Defines the output schema version and structure constants.
 * All extractors and writers should reference these to ensure consistency.
 */
class Schema
{
    public const VERSION = '0.1.0';

    /**
     * Top-level output directory structure.
     */
    public const DIR_MAGENTO = 'magento';
    public const DIR_KNOWLEDGE = 'knowledge';

    /**
     * Magento extractor output files.
     */
    public const FILE_MODULE_GRAPH = self::DIR_MAGENTO . '/module_graph';
    public const FILE_DI_PREFERENCES = self::DIR_MAGENTO . '/di_preference_overrides';
    public const FILE_PLUGINS = self::DIR_MAGENTO . '/plugins_interceptors';
    public const FILE_OBSERVERS = self::DIR_MAGENTO . '/events_observers';
    public const FILE_LAYOUT = self::DIR_MAGENTO . '/layout_handles';
    public const FILE_UI_COMPONENTS = self::DIR_MAGENTO . '/ui_components';
    public const FILE_DB_SCHEMA = self::DIR_MAGENTO . '/db_schema_patches';
    public const FILE_API_SURFACE = self::DIR_MAGENTO . '/api_surface';
    public const FILE_ROUTES = self::DIR_MAGENTO . '/routes';

    /**
     * Knowledge / analysis output files.
     */
    public const FILE_HOTSPOTS = self::DIR_KNOWLEDGE . '/known_hotspots';
    public const FILE_DEVIATIONS = self::DIR_KNOWLEDGE . '/custom_deviations.md';
    public const FILE_GLOSSARY = self::DIR_KNOWLEDGE . '/glossary.md';
    public const FILE_STANDARDS = self::DIR_KNOWLEDGE . '/coding_standards.md';

    /**
     * Top-level files.
     */
    public const FILE_MANIFEST = 'manifest.json';
    public const FILE_REPO_MAP = 'repo_map';

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
            'extractors' => [],
            'files' => [],
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
            'name' => 'string — fully qualified module name (e.g., Vendor_Module)',
            'path' => 'string — relative path from repo root',
            'version' => 'string — module version from module.xml',
            'dependencies' => 'array<string> — list of module names this depends on',
            'status' => 'string — enabled|disabled',
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

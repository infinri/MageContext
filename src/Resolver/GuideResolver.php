<?php

declare(strict_types=1);

namespace MageContext\Resolver;

class GuideResolver
{
    private string $contextDir;
    private array $index = [];

    public function __construct(string $contextDir)
    {
        $this->contextDir = rtrim($contextDir, '/');
    }

    /**
     * Load compiled context files into memory.
     */
    public function load(): void
    {
        $this->index = BundleLoader::load($this->contextDir);
    }

    /**
     * Get the full loaded index.
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array
    {
        return $this->index;
    }

    /**
     * Generate a development guide for a given task within specified areas.
     *
     * @param string $task The task description
     * @param array<string> $areas Magento areas/modules to focus on (e.g., ['salesrule', 'checkout'])
     * @return array<string, mixed> Structured guide
     */
    public function guide(string $task, array $areas): array
    {
        $guide = [];

        $guide['task'] = $task;
        $guide['areas'] = $areas;
        $guide['where_it_belongs'] = $this->resolveLocation($areas);
        $guide['extension_points'] = $this->findExtensionPoints($areas);
        $guide['patterns_used'] = $this->findPatterns($areas);
        $guide['patterns_to_avoid'] = $this->findAntiPatterns($areas);
        $guide['related_modules'] = $this->findRelatedModules($areas);
        $guide['execution_context'] = $this->findExecutionContext($areas);
        $guide['test_pointers'] = $this->generateTestPointers($areas, $task);
        $guide['risk_assessment'] = $this->assessRisk($areas);
        $guide['service_contracts'] = $this->findServiceContracts($areas);
        $guide['repository_guide'] = $this->findRepositoryGuide($areas);
        $guide['entity_context'] = $this->findEntityContext($areas);
        $guide['delegation_context'] = $this->findDelegationContext($areas);
        $guide['plugin_seam_guide'] = $this->findPluginSeamGuide($areas);
        $guide['api_safety'] = $this->findApiSafety($areas);
        $guide['implementation_guide'] = $this->findImplementationGuide($areas);

        // Filter out empty sections
        return array_filter($guide, fn($v) => !empty($v));
    }

    /**
     * Determine where the logic should live based on the requested areas.
     */
    private function resolveLocation(array $areas): array
    {
        $modules = $this->index['modules']['modules'] ?? [];
        $routes = $this->index['route_map']['routes'] ?? [];

        $locations = [];

        // Find modules matching the requested areas
        $matchedModules = BundleLoader::filterRecords($modules, $areas, ['name', 'path']);

        foreach ($matchedModules as $mod) {
            $locations[] = [
                'module' => $mod['name'],
                'path' => $mod['path'],
                'suggestion' => $this->suggestLocation($mod, $areas),
            ];
        }

        // If no custom modules match, suggest creating new or extending existing
        if (empty($locations)) {
            $locations[] = [
                'module' => 'New module recommended',
                'path' => 'app/code/SecondSwing/' . ucfirst($areas[0] ?? 'Custom'),
                'suggestion' => 'No existing custom module covers this area. Create a new module or extend the closest match.',
            ];
        }

        // Find relevant routes for the areas
        $matchedRoutes = BundleLoader::filterRecords($routes, $areas, ['route_id', 'front_name']);
        if (!empty($matchedRoutes)) {
            $locations[] = [
                'module' => 'Existing routes',
                'path' => '',
                'suggestion' => 'Routes already registered: ' . implode(', ', array_column($matchedRoutes, 'route_id')),
            ];
        }

        return $locations;
    }

    /**
     * Find extension points (plugins, observers, DI preferences) already used in the project for these areas.
     */
    private function findExtensionPoints(array $areas): array
    {
        $points = [];

        // Plugins on classes related to these areas
        $plugins = $this->index['plugin_chains']['plugins'] ?? [];
        $matchedPlugins = BundleLoader::filterRecords($plugins, $areas, ['target_class', 'plugin_class', 'source_file']);

        if (!empty($matchedPlugins)) {
            $byTarget = [];
            foreach ($matchedPlugins as $p) {
                $target = $p['target_class'];
                $byTarget[$target][] = [
                    'plugin' => $p['plugin_class'],
                    'methods' => $p['methods'] ?? [],
                    'scope' => $p['scope'],
                ];
            }

            $points['plugins'] = [
                'description' => 'Interceptors already registered in this area',
                'count' => count($matchedPlugins),
                'by_target' => $byTarget,
            ];
        }

        // Observers for events in these areas
        $observers = $this->index['event_graph']['observers'] ?? [];
        $matchedObservers = BundleLoader::filterRecords($observers, $areas, ['event_name', 'observer_class', 'source_file']);

        if (!empty($matchedObservers)) {
            $byEvent = [];
            foreach ($matchedObservers as $o) {
                $byEvent[$o['event_name']][] = [
                    'observer' => $o['observer_class'],
                    'scope' => $o['scope'],
                ];
            }

            $points['observers'] = [
                'description' => 'Event observers active in this area',
                'count' => count($matchedObservers),
                'by_event' => $byEvent,
            ];
        }

        // DI resolutions in this area
        $resolutions = $this->index['di_resolution_map']['resolutions'] ?? [];
        $matchedPrefs = BundleLoader::filterRecords($resolutions, $areas, ['interface', 'di_target_id']);

        if (!empty($matchedPrefs)) {
            $points['preferences'] = [
                'description' => 'DI preferences active in this area',
                'count' => count($matchedPrefs),
                'items' => array_map(fn($p) => [
                    'interface' => $p['interface'],
                    'preference' => $p['per_area']['global']['final_resolved_type'] ?? $p['preference'] ?? '',
                    'scope' => !empty($p['per_area']) ? implode(', ', array_keys($p['per_area'])) : ($p['scope'] ?? 'unknown'),
                ], $matchedPrefs),
            ];
        }

        return $points;
    }

    /**
     * Analyze patterns used in the project for these areas.
     */
    private function findPatterns(array $areas): array
    {
        $patterns = [];

        // Check what extension mechanism is dominant
        $plugins = BundleLoader::filterRecords(
            $this->index['plugin_chains']['plugins'] ?? [],
            $areas,
            ['target_class', 'plugin_class', 'source_file']
        );
        $observers = BundleLoader::filterRecords(
            $this->index['event_graph']['observers'] ?? [],
            $areas,
            ['event_name', 'observer_class', 'source_file']
        );
        $prefs = BundleLoader::filterRecords(
            $this->index['di_resolution_map']['resolutions'] ?? [],
            $areas,
            ['interface', 'di_target_id']
        );

        if (count($plugins) > count($observers)) {
            $patterns[] = [
                'pattern' => 'Plugins (Interceptors)',
                'usage' => 'Primary extension mechanism in this area (' . count($plugins) . ' plugins vs ' . count($observers) . ' observers)',
                'recommendation' => 'Follow the existing pattern: use plugins for method-level behavior changes.',
            ];
        } elseif (count($observers) > 0) {
            $patterns[] = [
                'pattern' => 'Observers',
                'usage' => 'Primary extension mechanism in this area (' . count($observers) . ' observers)',
                'recommendation' => 'Follow the existing pattern: use observers for cross-cutting events.',
            ];
        }

        if (count($prefs) > 0) {
            $patterns[] = [
                'pattern' => 'DI Preferences',
                'usage' => count($prefs) . ' preference override(s) in this area',
                'recommendation' => 'Preferences fully replace classes. Use only when plugin interception is insufficient.',
            ];
        }

        // Check for UI component patterns
        $uiComponents = BundleLoader::filterRecords(
            $this->index['ui_components']['components'] ?? [],
            $areas,
            ['name', 'source_file']
        );
        if (!empty($uiComponents)) {
            $types = array_unique(array_column($uiComponents, 'type'));
            $patterns[] = [
                'pattern' => 'UI Components',
                'usage' => count($uiComponents) . ' UI component(s): ' . implode(', ', $types),
                'recommendation' => 'Admin UI in this area uses UI components. Extend via XML configuration rather than overriding PHP classes.',
            ];
        }

        // Check for layout patterns
        $layout = BundleLoader::filterRecords(
            $this->index['layout_handles']['handles'] ?? [],
            $areas,
            ['handle', 'name', 'source_file']
        );
        if (!empty($layout)) {
            $patterns[] = [
                'pattern' => 'Layout XML',
                'usage' => count($layout) . ' layout element(s) in this area',
                'recommendation' => 'Frontend customization in this area is done via layout XML. Add blocks/containers through layout handles.',
            ];
        }

        return $patterns;
    }

    /**
     * Identify anti-patterns and things to avoid based on deviations in this area.
     */
    private function findAntiPatterns(array $areas): array
    {
        $deviations = $this->index['custom_deviations']['deviations'] ?? [];
        $matched = BundleLoader::filterRecords($deviations, $areas, ['message', 'source_file', 'type']);

        $antiPatterns = [];

        // Group by type
        $byType = [];
        foreach ($matched as $d) {
            $byType[$d['type']][] = $d;
        }

        if (!empty($byType['object_manager_usage'])) {
            $antiPatterns[] = [
                'pattern' => 'Direct ObjectManager usage',
                'occurrences' => count($byType['object_manager_usage']),
                'severity' => 'critical',
                'instruction' => 'DO NOT use ObjectManager::getInstance(). Always inject dependencies via constructor.',
                'examples_in_project' => array_slice(array_column($byType['object_manager_usage'], 'source_file'), 0, 3),
            ];
        }

        if (!empty($byType['core_preference_override'])) {
            $antiPatterns[] = [
                'pattern' => 'Core class preference overrides',
                'occurrences' => count($byType['core_preference_override']),
                'severity' => 'high',
                'instruction' => 'Avoid adding more preference overrides on core classes. Use plugins instead.',
                'examples_in_project' => array_slice(array_column($byType['core_preference_override'], 'source_file'), 0, 3),
            ];
        }

        if (!empty($byType['direct_sql'])) {
            $antiPatterns[] = [
                'pattern' => 'Direct SQL outside ResourceModel',
                'occurrences' => count($byType['direct_sql']),
                'severity' => 'medium',
                'instruction' => 'Use Repository or ResourceModel pattern for data access.',
                'examples_in_project' => array_slice(array_column($byType['direct_sql'], 'source_file'), 0, 3),
            ];
        }

        if (!empty($byType['template_override'])) {
            $antiPatterns[] = [
                'pattern' => 'Template overrides',
                'occurrences' => count($byType['template_override']),
                'severity' => 'medium',
                'instruction' => 'Minimize template overrides. Use ViewModels and layout XML to inject data instead of copying entire templates.',
                'examples_in_project' => array_slice(array_column($byType['template_override'], 'source_file'), 0, 3),
            ];
        }

        return $antiPatterns;
    }

    /**
     * Find related modules that interact with the specified areas.
     */
    /**
     * Find execution paths relevant to the specified areas.
     */
    private function findExecutionContext(array $areas): array
    {
        $paths = $this->index['execution_paths']['paths'] ?? [];
        $matched = BundleLoader::filterRecords($paths, $areas, ['entry_class', 'module', 'scenario']);

        if (empty($matched)) {
            return [];
        }

        return [
            'entry_points' => array_map(fn($p) => [
                'type' => $p['type'] ?? 'unknown',
                'class' => $p['entry_class'] ?? '',
                'module' => $p['module'] ?? '',
                'plugin_depth' => $p['complexity']['plugin_depth'] ?? 0,
                'observer_count' => $p['complexity']['observer_count'] ?? 0,
            ], $matched),
            'count' => count($matched),
        ];
    }

    private function findRelatedModules(array $areas): array
    {
        $modules = $this->index['modules']['modules'] ?? [];
        $matched = BundleLoader::filterRecords($modules, $areas, ['name', 'path']);

        // Also find modules that depend on matched modules
        $matchedNames = array_column($matched, 'name');
        $dependents = [];

        foreach ($modules as $mod) {
            $deps = $mod['sequence_dependencies'] ?? [];
            foreach ($deps as $dep) {
                if (in_array($dep, $matchedNames, true) && !in_array($mod['name'], $matchedNames, true)) {
                    $dependents[] = [
                        'name' => $mod['name'],
                        'path' => $mod['path'],
                        'depends_on' => $dep,
                    ];
                }
            }
        }

        return [
            'primary' => $matched,
            'dependents' => $dependents,
        ];
    }

    /**
     * Generate test pointers based on the task and area.
     */
    private function generateTestPointers(array $areas, string $task): array
    {
        $pointers = [];

        // Find existing test directories for matched modules
        $modules = $this->index['modules']['modules'] ?? [];
        $matched = BundleLoader::filterRecords($modules, $areas, ['name', 'path']);

        foreach ($matched as $mod) {
            $testDir = $mod['path'] . '/Test';
            $pointers[] = [
                'module' => $mod['name'],
                'test_directory' => $testDir,
                'suggestions' => $this->suggestTests($mod, $task),
            ];
        }

        // General test recommendations based on what extension points are used
        $plugins = BundleLoader::filterRecords(
            $this->index['plugin_chains']['plugins'] ?? [],
            $areas,
            ['target_class', 'plugin_class', 'source_file']
        );
        if (!empty($plugins)) {
            $pointers[] = [
                'module' => 'Plugin testing',
                'test_directory' => '',
                'suggestions' => [
                    'Write unit tests for each plugin class, mocking the subject and verifying before/after/around behavior',
                    'Test plugin sort order conflicts by verifying the expected chain execution order',
                    'Integration test: verify the full interceptor chain produces correct results end-to-end',
                ],
            ];
        }

        $observers = BundleLoader::filterRecords(
            $this->index['event_graph']['observers'] ?? [],
            $areas,
            ['event_name', 'observer_class', 'source_file']
        );
        if (!empty($observers)) {
            $pointers[] = [
                'module' => 'Observer testing',
                'test_directory' => '',
                'suggestions' => [
                    'Unit test each observer with a mocked Observer\\Event instance',
                    'Integration test: dispatch the event and verify observer side effects',
                ],
            ];
        }

        return $pointers;
    }

    /**
     * Assess risk of working in these areas based on deviation density and complexity.
     */
    private static function maxRisk(string $a, string $b): string
    {
        $ordinal = ['low' => 0, 'medium' => 1, 'high' => 2];
        return ($ordinal[$b] ?? 0) > ($ordinal[$a] ?? 0) ? $b : $a;
    }

    private function assessRisk(array $areas): array
    {
        $deviations = $this->index['custom_deviations']['deviations'] ?? [];
        $matched = BundleLoader::filterRecords($deviations, $areas, ['message', 'source_file', 'type']);

        $critical = count(array_filter($matched, fn($d) => $d['severity'] === 'critical'));
        $high = count(array_filter($matched, fn($d) => $d['severity'] === 'high'));

        $plugins = BundleLoader::filterRecords(
            $this->index['plugin_chains']['plugins'] ?? [],
            $areas,
            ['target_class', 'plugin_class', 'source_file']
        );

        $hotspots = $this->index['git_churn_hotspots']['hotspots'] ?? [];
        $hotFiles = BundleLoader::filterRecords($hotspots, $areas, ['file']);

        $riskLevel = 'low';
        $reasons = [];

        if ($critical > 0) {
            $riskLevel = 'high';
            $reasons[] = "{$critical} critical deviation(s) in this area";
        }
        if ($high > 3) {
            $riskLevel = self::maxRisk($riskLevel, 'medium');
            $reasons[] = "{$high} high-severity deviation(s)";
        }
        if (count($plugins) > 10) {
            $riskLevel = self::maxRisk($riskLevel, 'medium');
            $reasons[] = count($plugins) . " plugins intercepting classes in this area — high interception density";
        }
        if (count($hotFiles) > 3) {
            $reasons[] = count($hotFiles) . " frequently-changed files (churn hotspots) in this area";
        }
        // Check modifiability risk
        $modifiability = $this->index['modifiability']['modules'] ?? [];
        $matchedMod = BundleLoader::filterRecords($modifiability, $areas, ['module']);
        $highRiskModules = count(array_filter($matchedMod, fn($m) => ($m['modifiability_risk_score'] ?? 0) >= 0.7));
        if ($highRiskModules > 0) {
            $riskLevel = 'high';
            $reasons[] = "{$highRiskModules} high modifiability risk module(s)";
        }

        // Check architectural debt
        $debtItems = $this->index['architectural_debt']['debt_items'] ?? [];
        $matchedDebt = BundleLoader::filterRecords($debtItems, $areas, ['description']);
        if (count($matchedDebt) > 0) {
            if (count(array_filter($matchedDebt, fn($d) => $d['severity'] === 'high')) > 0) {
                $riskLevel = 'high';
            }
            $reasons[] = count($matchedDebt) . ' architectural debt item(s) in this area';
        }

        // Check layer violations
        $layerViolations = $this->index['layer_classification']['violations'] ?? [];
        $matchedViolations = BundleLoader::filterRecords($layerViolations, $areas, ['module', 'from', 'to']);
        if (count($matchedViolations) > 0) {
            $reasons[] = count($matchedViolations) . ' layer violation(s) in this area';
        }

        if (empty($reasons)) {
            $reasons[] = 'No significant risk indicators detected';
        }

        return [
            'level' => $riskLevel,
            'total_deviations' => count($matched),
            'critical' => $critical,
            'high' => $high,
            'plugin_density' => count($plugins),
            'churn_hotspots' => count($hotFiles),
            'high_risk_modules' => $highRiskModules ?? 0,
            'layer_violations' => count($matchedViolations ?? []),
            'debt_items' => count($matchedDebt ?? []),
            'reasons' => $reasons,
        ];
    }

    private function suggestLocation(array $module, array $areas): string
    {
        $name = $module['name'] ?? '';
        $path = $module['path'] ?? '';

        return "Existing module '{$name}' at {$path} covers this area. Add new logic here if it fits the module's responsibility.";
    }

    private function findServiceContracts(array $areas): array
    {
        $contracts = $this->index['service_contracts']['contracts'] ?? [];
        $matched = BundleLoader::filterRecords($contracts, $areas, ['interface', 'module', 'source_file']);
        if (empty($matched)) {
            return [];
        }

        return [
            'description' => 'Service contract interfaces available in this area',
            'count' => count($matched),
            'contracts' => array_map(fn($c) => [
                'interface' => $c['interface'],
                'method_count' => $c['method_count'] ?? 0,
                'has_di_binding' => !empty($c['di_bindings']),
                'webapi_exposed' => !empty($c['webapi_routes']),
            ], $matched),
        ];
    }

    private function findRepositoryGuide(array $areas): array
    {
        $repos = $this->index['repository_patterns']['repositories'] ?? [];
        $matched = BundleLoader::filterRecords($repos, $areas, ['interface', 'entity_name', 'module']);
        if (empty($matched)) {
            return [];
        }

        $guide = $this->index['repository_patterns']['search_criteria_guide'] ?? [];
        $matchedGuide = BundleLoader::filterRecords($guide, $areas, ['interface', 'entity']);

        return [
            'description' => 'Repository interfaces and lookup patterns for this area',
            'repositories' => array_map(fn($r) => [
                'interface' => $r['interface'],
                'entity' => $r['entity_name'],
                'has_get_list' => $r['has_get_list'] ?? false,
                'supports_search_criteria' => $r['supports_search_criteria'] ?? false,
                'crud_score' => $r['crud_score'] ?? 0,
            ], $matched),
            'search_criteria_guide' => $matchedGuide,
        ];
    }

    private function findEntityContext(array $areas): array
    {
        $entities = $this->index['entity_relationships']['entities'] ?? [];
        $matched = BundleLoader::filterRecords($entities, $areas, ['entity_class', 'table', 'module']);
        if (empty($matched)) {
            return [];
        }

        $relationships = $this->index['entity_relationships']['relationships'] ?? [];
        $tables = array_column($matched, 'table');
        $relatedRels = array_filter($relationships, fn($r) =>
            in_array($r['from_table'] ?? '', $tables, true) ||
            in_array($r['to_table'] ?? '', $tables, true)
        );

        $invariants = $this->index['entity_relationships']['domain_invariants'] ?? [];
        $matchedInvariants = BundleLoader::filterRecords($invariants, $areas, ['class', 'module']);

        return [
            'description' => 'Entity classes, table mappings, and domain constraints',
            'entities' => array_map(fn($e) => [
                'class' => $e['entity_class'],
                'table' => $e['table'],
                'type' => $e['type'] ?? 'flat',
                'id_field' => $e['id_field'] ?? null,
            ], $matched),
            'relationships' => array_values($relatedRels),
            'domain_invariants' => $matchedInvariants,
        ];
    }

    private function findDelegationContext(array $areas): array
    {
        $chains = $this->index['call_graph']['delegation_chains'] ?? [];
        $matched = BundleLoader::filterRecords($chains, $areas, ['service_interface', 'final_concrete', 'module', 'url']);
        if (empty($matched)) {
            return [];
        }

        $guestPairs = $this->index['call_graph']['guest_auth_pairs'] ?? [];
        $matchedPairs = BundleLoader::filterRecords($guestPairs, $areas, ['guest_interface', 'auth_interface']);

        return [
            'description' => 'Delegation chains showing how entry points resolve to concrete classes',
            'chains' => array_map(fn($c) => [
                'context' => $c['context'],
                'interface' => $c['service_interface'],
                'concrete' => $c['final_concrete'],
                'depth' => $c['delegation_depth'] ?? 0,
                'has_divergence' => $c['has_cross_area_divergence'] ?? false,
            ], array_slice($matched, 0, 20)),
            'guest_auth_pairs' => $matchedPairs,
            'total_chains' => count($matched),
        ];
    }

    private function findPluginSeamGuide(array $areas): array
    {
        $seams = $this->index['plugin_seam_timing']['seams'] ?? [];
        $matched = BundleLoader::filterRecords($seams, $areas, ['target_class', 'target_method', 'seam_id']);
        if (empty($matched)) {
            return [];
        }

        $highRisk = array_filter($matched, fn($s) => ($s['risk_level'] ?? 'low') !== 'low');

        return [
            'description' => 'Plugin seams with timing, side-effect warnings, and recommendations',
            'total_seams' => count($matched),
            'high_risk_seams' => array_values(array_map(fn($s) => [
                'target' => $s['target_class'] . '::' . $s['target_method'],
                'risk_level' => $s['risk_level'] ?? 'low',
                'total_plugins' => $s['total_plugins'] ?? 0,
                'has_around' => $s['has_around'] ?? false,
                'recommendations' => $s['recommendations'] ?? [],
            ], $highRisk)),
        ];
    }

    private function findApiSafety(array $areas): array
    {
        $matrix = $this->index['safe_api_matrix']['class_matrix'] ?? [];
        $matched = BundleLoader::filterRecords($matrix, $areas, ['class', 'module']);
        if (empty($matched)) {
            return [];
        }

        $deprecated = $this->index['safe_api_matrix']['deprecated_methods'] ?? [];
        $matchedDeprecated = BundleLoader::filterRecords($deprecated, $areas, ['class', 'method_id']);

        return [
            'description' => 'API stability classification — safe vs internal vs deprecated methods',
            'classes_analyzed' => count($matched),
            'deprecated_methods' => array_map(fn($d) => [
                'method' => $d['method_id'] ?? $d['method'],
                'replacement' => $d['replacement'] ?? null,
            ], $matchedDeprecated),
        ];
    }

    private function findImplementationGuide(array $areas): array
    {
        $impls = $this->index['implementation_patterns']['implementations'] ?? [];
        $matched = BundleLoader::filterRecords($impls, $areas, ['interface', 'concrete_class', 'module']);
        if (empty($matched)) {
            return [];
        }

        return [
            'description' => 'Concrete implementation patterns — constructor dependencies and design patterns used',
            'implementations' => array_map(fn($i) => [
                'interface' => $i['interface'],
                'concrete' => $i['concrete_class'],
                'dependency_count' => $i['dependency_count'] ?? 0,
                'patterns' => $i['patterns'] ?? [],
                'extra_methods_count' => count($i['extra_methods'] ?? []),
            ], $matched),
        ];
    }

    private function suggestTests(array $module, string $task): array
    {
        $suggestions = [];
        $suggestions[] = "Add unit tests in {$module['path']}/Test/Unit/";
        $suggestions[] = "Add integration tests in {$module['path']}/Test/Integration/ if the task involves database or DI";

        if (stripos($task, 'api') !== false || stripos($task, 'endpoint') !== false) {
            $suggestions[] = "Add API functional tests in {$module['path']}/Test/Api/";
        }

        return $suggestions;
    }
}

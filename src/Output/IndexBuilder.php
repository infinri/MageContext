<?php

declare(strict_types=1);

namespace MageContext\Output;

/**
 * C.3: IndexBuilder — generates reverse indexes from extractor data.
 *
 * Reverse indexes enable "given X, find all facts about X" queries in O(1).
 *
 * Indexes produced:
 * - by_class: class_id → {file_id, module_id, symbol_type, plugins[], di_resolutions[], events_observed[]}
 * - by_module: module_id → {files[], classes[], plugins_declared[], events_dispatched[], routes[], crons[], debt_items[]}
 * - by_event: event_id → {dispatched_by, observers[], cross_module_count}
 * - by_route: route_id → {area, method, path, controller, module_id, plugins_on_controller[]}
 *
 * All references use canonical IDs from IdentityResolver.
 * Output: reverse_index/reverse_index.json
 */
class IndexBuilder
{
    /**
     * Build all reverse indexes from aggregated extractor data.
     *
     * @param array<string, array> $allData All extractor data keyed by extractor name
     * @return array{by_class: array, by_module: array, by_event: array, by_route: array}
     */
    public function build(array $allData): array
    {
        $byClass = $this->buildClassIndex($allData);
        $byModule = $this->buildModuleIndex($allData);
        $byEvent = $this->buildEventIndex($allData);
        $byRoute = $this->buildRouteIndex($allData);

        return [
            'by_class' => $byClass,
            'by_module' => $byModule,
            'by_event' => $byEvent,
            'by_route' => $byRoute,
            'summary' => [
                'indexed_classes' => count($byClass),
                'indexed_modules' => count($byModule),
                'indexed_events' => count($byEvent),
                'indexed_routes' => count($byRoute),
            ],
        ];
    }

    /**
     * class_id → {file_id, module_id, symbol_type, plugins[], di_resolutions[], events_observed[]}
     */
    private function buildClassIndex(array $allData): array
    {
        $index = [];

        // Seed from symbol_index
        foreach ($allData['symbol_index']['symbols'] ?? [] as $sym) {
            $classId = $sym['class_id'];
            $index[$classId] = [
                'class_id' => $classId,
                'fqcn' => $sym['fqcn'] ?? $classId,
                'file_id' => $sym['file_id'],
                'module_id' => $sym['module_id'],
                'symbol_type' => $sym['symbol_type'],
                'extends' => $sym['extends'] ?? null,
                'implements' => $sym['implements'] ?? [],
                'plugins_on' => [],
                'di_resolutions' => [],
                'events_observed' => [],
            ];
        }

        // Enrich with plugin data
        foreach ($allData['plugin_chains'] ?? [] as $targetClass => $chainData) {
            if (!is_array($chainData) || !isset($chainData['plugins'])) {
                continue;
            }
            // Target class has plugins on it
            $targetClassId = $this->normalizeClassId($targetClass);
            if (isset($index[$targetClassId])) {
                foreach ($chainData['plugins'] as $plugin) {
                    $index[$targetClassId]['plugins_on'][] = [
                        'plugin_class' => $plugin['plugin_class'] ?? '',
                        'type' => $plugin['type'] ?? '',
                        'sort_order' => $plugin['sort_order'] ?? 0,
                    ];
                }
            }

            // Plugin classes themselves
            foreach ($chainData['plugins'] as $plugin) {
                $pluginClassId = $this->normalizeClassId($plugin['plugin_class'] ?? '');
                if ($pluginClassId !== '' && isset($index[$pluginClassId])) {
                    // Mark this class as being a plugin
                    if (!isset($index[$pluginClassId]['is_plugin_for'])) {
                        $index[$pluginClassId]['is_plugin_for'] = [];
                    }
                    $index[$pluginClassId]['is_plugin_for'][] = $targetClassId;
                }
            }
        }

        // Enrich with DI resolution data
        foreach ($allData['di_resolution_map']['resolutions'] ?? [] as $resolution) {
            $interfaceId = $this->normalizeClassId($resolution['interface'] ?? '');
            $resolvedId = $this->normalizeClassId($resolution['final_resolved_type'] ?? '');

            if ($interfaceId !== '' && isset($index[$interfaceId])) {
                $index[$interfaceId]['di_resolutions'][] = [
                    'resolved_to' => $resolvedId,
                    'area' => $resolution['area'] ?? 'global',
                    'confidence' => $resolution['confidence'] ?? 1.0,
                ];
            }
        }

        // Enrich with observer data
        foreach ($allData['event_graph']['event_graph'] ?? [] as $event) {
            foreach ($event['listeners'] ?? $event['observers'] ?? [] as $observer) {
                $observerClassId = $this->normalizeClassId($observer['observer_class'] ?? $observer['class'] ?? '');
                if ($observerClassId !== '' && isset($index[$observerClassId])) {
                    $index[$observerClassId]['events_observed'][] = $event['event_id'] ?? $event['event'] ?? '';
                }
            }
        }

        return $index;
    }

    /**
     * module_id → {files[], classes[], plugins_declared[], events_dispatched[], routes[], crons[], debt_items[]}
     */
    private function buildModuleIndex(array $allData): array
    {
        $index = [];

        // Seed from modules
        foreach ($allData['modules']['modules'] ?? [] as $mod) {
            $moduleId = $mod['id'] ?? $mod['name'] ?? '';
            if ($moduleId === '') {
                continue;
            }
            $index[$moduleId] = [
                'module_id' => $moduleId,
                'type' => $mod['type'] ?? 'module',
                'path' => $mod['path'] ?? '',
                'files' => [],
                'classes' => [],
                'plugins_declared' => [],
                'events_dispatched' => [],
                'events_observed' => [],
                'routes' => [],
                'crons' => [],
                'cli_commands' => [],
                'debt_items' => [],
                'deviations' => 0,
            ];
        }

        // Files from file_index
        foreach ($allData['file_index']['files'] ?? [] as $file) {
            $moduleId = $file['module_id'] ?? '';
            if ($moduleId !== '' && isset($index[$moduleId])) {
                $index[$moduleId]['files'][] = $file['file_id'];
            }
        }

        // Classes from symbol_index
        foreach ($allData['symbol_index']['symbols'] ?? [] as $sym) {
            $moduleId = $sym['module_id'] ?? '';
            if ($moduleId !== '' && isset($index[$moduleId])) {
                $index[$moduleId]['classes'][] = $sym['class_id'];
            }
        }

        // Plugins
        foreach ($allData['plugin_chains'] ?? [] as $targetClass => $chainData) {
            if (!is_array($chainData) || !isset($chainData['plugins'])) {
                continue;
            }
            foreach ($chainData['plugins'] as $plugin) {
                $pluginModule = $plugin['module'] ?? $plugin['declared_by'] ?? '';
                if ($pluginModule !== '' && isset($index[$pluginModule])) {
                    $index[$pluginModule]['plugins_declared'][] = [
                        'plugin_class' => $plugin['plugin_class'] ?? '',
                        'target' => $targetClass,
                    ];
                }
            }
        }

        // Events
        foreach ($allData['event_graph']['event_graph'] ?? [] as $event) {
            $eventId = $event['event_id'] ?? $event['event'] ?? '';
            foreach ($event['listeners'] ?? $event['observers'] ?? [] as $observer) {
                $obsModule = $observer['module'] ?? $observer['declared_by'] ?? '';
                if ($obsModule !== '' && isset($index[$obsModule])) {
                    $index[$obsModule]['events_observed'][] = $eventId;
                }
            }
        }

        // Routes
        foreach ($allData['route_map']['routes'] ?? [] as $route) {
            $routeModule = $route['declared_by'] ?? $route['module'] ?? '';
            $routeId = $route['route_id'] ?? '';
            if ($routeModule !== '' && isset($index[$routeModule]) && $routeId !== '') {
                $index[$routeModule]['routes'][] = $routeId;
            }
        }

        // Crons
        foreach ($allData['cron_map']['cron_jobs'] ?? [] as $cron) {
            $cronModule = $cron['declared_by'] ?? $cron['module'] ?? '';
            $cronId = $cron['cron_id'] ?? '';
            if ($cronModule !== '' && isset($index[$cronModule]) && $cronId !== '') {
                $index[$cronModule]['crons'][] = $cronId;
            }
        }

        // CLI commands
        foreach ($allData['cli_commands']['commands'] ?? [] as $cmd) {
            $cmdModule = $cmd['declared_by'] ?? $cmd['module'] ?? '';
            $cmdName = $cmd['command_name'] ?? '';
            if ($cmdModule !== '' && isset($index[$cmdModule]) && $cmdName !== '') {
                $index[$cmdModule]['cli_commands'][] = $cmdName;
            }
        }

        // Debt
        foreach ($allData['architectural_debt']['debt_items'] ?? [] as $debt) {
            $modules = $debt['modules'] ?? [];
            foreach ($modules as $moduleId) {
                if (isset($index[$moduleId])) {
                    $index[$moduleId]['debt_items'][] = [
                        'type' => $debt['type'] ?? '',
                        'message' => $debt['message'] ?? '',
                    ];
                }
            }
        }

        // Deviations
        foreach ($allData['custom_deviations']['deviations'] ?? [] as $dev) {
            $devModule = $dev['module'] ?? $dev['declared_by'] ?? '';
            if ($devModule !== '' && isset($index[$devModule])) {
                $index[$devModule]['deviations']++;
            }
        }

        return $index;
    }

    /**
     * event_id → {dispatched_by, observers[], cross_module_count}
     */
    private function buildEventIndex(array $allData): array
    {
        $index = [];

        foreach ($allData['event_graph']['event_graph'] ?? [] as $event) {
            $eventId = $event['event_id'] ?? $event['event'] ?? '';
            if ($eventId === '') {
                continue;
            }

            $observers = [];
            $modules = [];
            foreach ($event['listeners'] ?? $event['observers'] ?? [] as $observer) {
                $obsModule = $observer['module'] ?? $observer['declared_by'] ?? '';
                $observers[] = [
                    'observer_class' => $observer['observer_class'] ?? $observer['class'] ?? '',
                    'module_id' => $obsModule,
                    'method' => $observer['method'] ?? 'execute',
                ];
                if ($obsModule !== '') {
                    $modules[$obsModule] = true;
                }
            }

            $index[$eventId] = [
                'event_id' => $eventId,
                'observer_count' => count($observers),
                'observers' => $observers,
                'cross_module_count' => count($modules),
                'risk_score' => $event['risk_score'] ?? 0,
            ];
        }

        return $index;
    }

    /**
     * route_id → {area, method, path, controller, module_id, plugins_on_controller[]}
     */
    private function buildRouteIndex(array $allData): array
    {
        $index = [];

        // Build plugin lookup by target class for quick enrichment
        $pluginsByTarget = [];
        foreach ($allData['plugin_chains'] ?? [] as $targetClass => $chainData) {
            if (!is_array($chainData) || !isset($chainData['plugins'])) {
                continue;
            }
            $pluginsByTarget[$this->normalizeClassId($targetClass)] = $chainData['plugins'];
        }

        foreach ($allData['route_map']['routes'] ?? [] as $route) {
            $routeId = $route['route_id'] ?? '';
            if ($routeId === '') {
                continue;
            }

            $controller = $route['action_class'] ?? $route['controller'] ?? '';
            $controllerClassId = $this->normalizeClassId($controller);

            $pluginsOnController = [];
            if (isset($pluginsByTarget[$controllerClassId])) {
                foreach ($pluginsByTarget[$controllerClassId] as $plugin) {
                    $pluginsOnController[] = [
                        'plugin_class' => $plugin['plugin_class'] ?? '',
                        'type' => $plugin['type'] ?? '',
                    ];
                }
            }

            $index[$routeId] = [
                'route_id' => $routeId,
                'area' => $route['area'] ?? '',
                'method' => $route['method'] ?? 'GET',
                'path' => $route['url'] ?? $route['path'] ?? '',
                'controller' => $controller,
                'module_id' => $route['declared_by'] ?? $route['module'] ?? '',
                'plugins_on_controller' => $pluginsOnController,
            ];
        }

        return $index;
    }

    /**
     * Normalize a class name to a class_id format (lowercase backslash-separated FQCN).
     */
    private function normalizeClassId(string $class): string
    {
        $class = ltrim($class, '\\');
        return $class !== '' ? strtolower($class) : '';
    }
}

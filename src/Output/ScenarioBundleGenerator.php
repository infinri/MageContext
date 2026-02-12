<?php

declare(strict_types=1);

namespace MageContext\Output;

class ScenarioBundleGenerator
{
    /** @var array Coverage tracking for scenario_coverage.json */
    private array $coverageStats = [
        'total_seeds' => 0,
        'total_scenarios' => 0,
        'matched' => 0,
        'unmatched' => 0,
        'unmatched_by_type' => [],
        'unmatched_details' => [],
    ];
    /**
     * Generate per-entry-point scenario bundles from aggregated extractor data.
     *
     * C.4: Uses ScenarioSeedResolver for deterministic scenario_ids.
     * Each bundle includes:
     * - scenario_id (stable across runs)
     * - canonical_entry (frozen shape)
     * - Entry point info with step_kind
     * - Dependency slice (modules involved)
     * - Execution chain (DI, plugins, observers)
     * - Affected modules (with reverse_index references)
     * - Risk assessment
     * - QA concerns
     *
     * @param array<string, array> $allData All extractor data keyed by extractor name
     * @return array<string, array> Scenario bundles keyed by scenario name
     */
    public function generate(array $allData): array
    {
        $executionPaths = $allData['execution_paths']['paths'] ?? [];

        if (empty($executionPaths)) {
            return [];
        }

        $moduleData = $allData['modules']['modules'] ?? [];
        $modifiability = $allData['modifiability']['modules'] ?? [];
        $debtItems = $allData['architectural_debt']['debt_items'] ?? [];
        $layerViolations = $allData['layer_classification']['violations'] ?? [];
        $perfIndicators = $allData['performance']['indicators'] ?? [];
        $hotspots = $allData['hotspot_ranking']['rankings'] ?? [];

        // C.4: Resolve scenario seeds for deterministic IDs
        $seedResolver = new ScenarioSeedResolver();
        $seeds = $seedResolver->resolve($allData);
        $seedMap = [];
        foreach ($seeds as $seed) {
            $seedMap[$this->seedKey($seed['canonical_entry'])] = $seed;
        }

        // Build class-based reverse lookups for seed matching
        $cronClassToSeed = [];
        foreach ($allData['cron_map']['cron_jobs'] ?? [] as $cron) {
            $instance = $cron['instance'] ?? '';
            if ($instance !== '') {
                $key = 'cron:' . ($cron['group'] ?? 'default') . ':' . ($cron['cron_id'] ?? '');
                if (isset($seedMap[$key])) {
                    $cronClassToSeed[strtolower(ltrim($instance, '\\'))] = $seedMap[$key];
                }
            }
        }
        $cliClassToSeed = [];
        foreach ($allData['cli_commands']['commands'] ?? [] as $cmd) {
            $class = $cmd['class'] ?? '';
            if ($class !== '') {
                $key = 'cli:' . ($cmd['command_name'] ?? '');
                if (isset($seedMap[$key])) {
                    $cliClassToSeed[strtolower(ltrim($class, '\\'))] = $seedMap[$key];
                }
            }
        }

        // Build lookup maps for fast access
        $modifiabilityMap = $this->buildModuleMap($modifiability, 'module');
        $hotspotMap = $this->buildModuleMap($hotspots, 'module');
        $moduleMap = $this->buildModuleMap($moduleData, 'name');

        // Reverse index reference for module enrichment
        $reverseModuleIndex = $allData['reverse_index']['by_module'] ?? [];

        $scenarios = [];
        $matchStatus = [];    // scenarioName => bool (true=matched)
        $unmatchedDetails = []; // scenarioName => detail array

        foreach ($executionPaths as $path) {
            $scenarioName = $path['scenario'] ?? 'unknown';
            $entryClass = $path['entry_class'] ?? '';
            $entryModule = $path['module'] ?? 'unknown';

            // Resolve scenario_id from seed resolver
            $seed = $this->matchSeed($path, $seedMap, $cronClassToSeed, $cliClassToSeed);

            // Collect all modules involved in this execution path
            $affectedModules = [$entryModule];

            // From DI resolution chain
            foreach ($path['di_resolution_chain'] ?? [] as $step) {
                $mod = $this->resolveModuleFromClass($step['resolved_to'] ?? '');
                if ($mod !== 'unknown') {
                    $affectedModules[] = $mod;
                }
            }

            // From plugin stack
            foreach ($path['plugin_stack'] ?? [] as $plugin) {
                $mod = $this->resolveModuleFromClass($plugin['plugin_class'] ?? '');
                if ($mod !== 'unknown') {
                    $affectedModules[] = $mod;
                }
            }

            // From observer triggers
            foreach ($path['observer_triggers'] ?? [] as $trigger) {
                foreach ($trigger['observers'] ?? [] as $observer) {
                    $mod = $this->resolveModuleFromClass($observer['observer_class'] ?? '');
                    if ($mod !== 'unknown') {
                        $affectedModules[] = $mod;
                    }
                }
            }

            $affectedModules = array_unique($affectedModules);

            // Build dependency slice
            $dependencySlice = [];
            foreach ($affectedModules as $mod) {
                $modInfo = $moduleMap[$mod] ?? null;
                if ($modInfo !== null) {
                    $dependencySlice[] = [
                        'module' => $mod,
                        'path' => $modInfo['path'] ?? '',
                        'dependencies' => $modInfo['sequence_dependencies'] ?? [],
                    ];
                }
            }

            // Assess risk for this scenario
            $risk = $this->assessScenarioRisk(
                $affectedModules,
                $modifiabilityMap,
                $hotspotMap,
                $debtItems,
                $layerViolations,
                $perfIndicators,
                $path
            );

            // Generate QA concerns
            $qaConcerns = $this->generateQaConcerns(
                $path,
                $affectedModules,
                $layerViolations,
                $debtItems,
                $perfIndicators
            );

            $bundle = [
                'scenario' => $scenarioName,
                'entry_point' => [
                    'type' => $path['type'] ?? 'unknown',
                    'class' => $entryClass,
                    'module' => $entryModule,
                    'method' => $path['entry_method'] ?? 'execute',
                    'step_kind' => $this->inferStepKind($path),
                ],
                'execution_chain' => [
                    'di_resolution_chain' => $path['di_resolution_chain'] ?? [],
                    'plugin_stack' => $path['plugin_stack'] ?? [],
                    'observer_triggers' => $path['observer_triggers'] ?? [],
                    'complexity' => $path['complexity'] ?? [],
                ],
                'affected_modules' => $affectedModules,
                'dependency_slice' => $dependencySlice,
                'risk' => $risk,
                'qa_concerns' => $qaConcerns,
            ];

            // C.4: Add scenario_id + canonical_entry from seed resolver
            if ($seed !== null) {
                $bundle['scenario_id'] = $seed['scenario_id'];
                $bundle['canonical_entry'] = $seed['canonical_entry'];
                $matchStatus[$scenarioName] = true;
            } else {
                $bundle['scenario_id'] = ScenarioSeedResolver::scenarioId([
                    'type' => $path['type'] ?? 'unknown',
                    'class' => $entryClass,
                ]);
                // Only track if not already matched (later path may overwrite)
                if (!isset($matchStatus[$scenarioName])) {
                    $matchStatus[$scenarioName] = false;
                    $unmatchedDetails[$scenarioName] = [
                        'scenario_id' => $bundle['scenario_id'],
                        'scenario_name' => $scenarioName,
                        'entry_type' => $path['type'] ?? 'unknown',
                        'reason_code' => $this->classifyUnmatchedReason($path['type'] ?? 'unknown', $entryClass, $seedMap, $cronClassToSeed, $cliClassToSeed),
                    ];
                }
            }

            // C.4: Add reverse_index references for affected modules
            $moduleRefs = [];
            foreach ($affectedModules as $mod) {
                if (isset($reverseModuleIndex[$mod])) {
                    $ri = $reverseModuleIndex[$mod];
                    $moduleRefs[$mod] = [
                        'class_count' => count($ri['classes'] ?? []),
                        'route_count' => count($ri['routes'] ?? []),
                        'debt_count' => count($ri['debt_items'] ?? []),
                        'deviations' => $ri['deviations'] ?? 0,
                    ];
                }
            }
            $bundle['module_refs'] = $moduleRefs;

            $scenarios[$scenarioName] = $bundle;
        }

        // Compute coverage stats from final unique scenarios
        $matched = count(array_filter($matchStatus, fn($v) => $v === true));
        $unmatched = count(array_filter($matchStatus, fn($v) => $v === false));
        // Remove unmatched details for scenarios that were later matched
        $finalUnmatched = array_filter($unmatchedDetails, fn($k) => ($matchStatus[$k] ?? true) === false, ARRAY_FILTER_USE_KEY);
        $unmatchedByType = [];
        foreach ($finalUnmatched as $d) {
            $t = $d['entry_type'];
            $unmatchedByType[$t] = ($unmatchedByType[$t] ?? 0) + 1;
        }

        $this->coverageStats = [
            'total_seeds' => count($seeds),
            'total_scenarios' => count($scenarios),
            'matched' => $matched,
            'unmatched' => $unmatched,
            'unmatched_by_type' => $unmatchedByType,
            'unmatched_details' => array_values($finalUnmatched),
        ];

        return $scenarios;
    }

    /**
     * Get scenario coverage report for scenario_coverage.json.
     *
     * @return array
     */
    public function getCoverageReport(): array
    {
        return $this->coverageStats;
    }

    /**
     * Classify why an execution path didn't match a seed.
     */
    private function classifyUnmatchedReason(string $pathType, string $entryClass, array $seedMap, array $cronClassToSeed, array $cliClassToSeed): string
    {
        return match ($pathType) {
            'controller' => 'no_matching_route_id',
            'cron' => $entryClass !== '' ? 'cron_class_not_in_crontab_xml' : 'missing_entry_class',
            'cli_command' => $entryClass !== '' ? 'cli_class_not_in_di_xml' : 'missing_entry_class',
            default => 'unsupported_entry_type',
        };
    }

    /**
     * Assess risk for a single scenario based on its affected modules and execution path.
     */
    private function assessScenarioRisk(
        array $affectedModules,
        array $modifiabilityMap,
        array $hotspotMap,
        array $debtItems,
        array $layerViolations,
        array $perfIndicators,
        array $path
    ): array {
        $reasons = [];
        $riskScore = 0.0;

        // 1. Modifiability risk of affected modules
        $maxModRisk = 0.0;
        foreach ($affectedModules as $mod) {
            $modRisk = $modifiabilityMap[$mod]['modifiability_risk_score'] ?? 0;
            if ($modRisk > $maxModRisk) {
                $maxModRisk = $modRisk;
            }
        }
        if ($maxModRisk >= 0.7) {
            $reasons[] = "High modifiability risk module involved (score: {$maxModRisk})";
            $riskScore += 0.3;
        } elseif ($maxModRisk >= 0.4) {
            $riskScore += 0.15;
        }

        // 2. Plugin depth
        $pluginDepth = $path['complexity']['plugin_depth'] ?? 0;
        if ($pluginDepth > 5) {
            $reasons[] = "Deep plugin stack ({$pluginDepth} plugins)";
            $riskScore += 0.25;
        } elseif ($pluginDepth > 3) {
            $reasons[] = "Moderate plugin stack ({$pluginDepth} plugins)";
            $riskScore += 0.1;
        }

        // 3. Observer count
        $observerCount = $path['complexity']['observer_count'] ?? 0;
        if ($observerCount > 5) {
            $reasons[] = "High observer count ({$observerCount} observers)";
            $riskScore += 0.2;
        } elseif ($observerCount > 2) {
            $riskScore += 0.05;
        }

        // 4. Cross-module span
        $moduleCount = count($affectedModules);
        if ($moduleCount > 5) {
            $reasons[] = "Wide cross-module span ({$moduleCount} modules)";
            $riskScore += 0.15;
        }

        // 5. Debt items involving affected modules
        $relevantDebt = 0;
        foreach ($debtItems as $item) {
            foreach ($item['modules'] ?? [] as $mod) {
                if (in_array($mod, $affectedModules, true)) {
                    $relevantDebt++;
                    break;
                }
            }
        }
        if ($relevantDebt > 0) {
            $reasons[] = "{$relevantDebt} architectural debt item(s) in affected modules";
            $riskScore += min(0.2, $relevantDebt * 0.05);
        }

        // 6. Layer violations in affected modules
        $relevantViolations = 0;
        foreach ($layerViolations as $v) {
            if (in_array($v['module'] ?? '', $affectedModules, true)) {
                $relevantViolations++;
            }
        }
        if ($relevantViolations > 0) {
            $reasons[] = "{$relevantViolations} layer violation(s) in affected modules";
            $riskScore += min(0.1, $relevantViolations * 0.02);
        }

        $riskScore = min(1.0, $riskScore);
        $level = $riskScore >= 0.6 ? 'high' : ($riskScore >= 0.3 ? 'medium' : 'low');

        if (empty($reasons)) {
            $reasons[] = 'No significant risk indicators detected';
        }

        return [
            'level' => $level,
            'score' => round($riskScore, 3),
            'reasons' => $reasons,
            'affected_module_count' => $moduleCount,
            'plugin_depth' => $pluginDepth,
            'observer_count' => $observerCount,
        ];
    }

    /**
     * Generate QA concerns for a scenario.
     */
    private function generateQaConcerns(
        array $path,
        array $affectedModules,
        array $layerViolations,
        array $debtItems,
        array $perfIndicators
    ): array {
        $concerns = [];

        // Plugin ordering concerns
        $pluginStack = $path['plugin_stack'] ?? [];
        if (count($pluginStack) > 1) {
            $concerns[] = [
                'type' => 'plugin_ordering',
                'message' => 'Multiple plugins in chain — verify sort_order is correct and test execution sequence',
                'severity' => count($pluginStack) > 3 ? 'high' : 'medium',
            ];
        }

        // Around plugins (can short-circuit)
        $aroundCount = $path['complexity']['around_count'] ?? 0;
        if ($aroundCount > 0) {
            $concerns[] = [
                'type' => 'around_plugin',
                'message' => "{$aroundCount} around plugin(s) can short-circuit execution — verify proceed() is called",
                'severity' => 'high',
            ];
        }

        // Observer side effects
        $observerCount = $path['complexity']['observer_count'] ?? 0;
        if ($observerCount > 0) {
            $concerns[] = [
                'type' => 'observer_side_effects',
                'message' => "{$observerCount} observer(s) triggered — verify no unintended state mutations",
                'severity' => $observerCount > 3 ? 'high' : 'medium',
            ];
        }

        // Cross-module state dependency
        if (count($affectedModules) > 3) {
            $concerns[] = [
                'type' => 'cross_module_state',
                'message' => 'Execution spans ' . count($affectedModules) . ' modules — test state consistency across module boundaries',
                'severity' => 'medium',
            ];
        }

        // DI resolution chain (preference overrides)
        $diChain = $path['di_resolution_chain'] ?? [];
        if (!empty($diChain)) {
            $concerns[] = [
                'type' => 'di_resolution',
                'message' => count($diChain) . ' DI preference(s) in chain — verify implementations satisfy interface contracts',
                'severity' => 'medium',
            ];
        }

        // Layer violations in scope
        $scopeViolations = 0;
        foreach ($layerViolations as $v) {
            if (in_array($v['module'] ?? '', $affectedModules, true)) {
                $scopeViolations++;
            }
        }
        if ($scopeViolations > 0) {
            $concerns[] = [
                'type' => 'layer_violation',
                'message' => "{$scopeViolations} layer violation(s) in scope — these may cause unexpected coupling",
                'severity' => 'high',
            ];
        }

        return $concerns;
    }

    /**
     * Build a lookup map from an array of records keyed by a specific field.
     */
    private function buildModuleMap(array $records, string $keyField): array
    {
        $map = [];
        foreach ($records as $record) {
            $key = $record[$keyField] ?? '';
            if ($key !== '') {
                $map[$key] = $record;
            }
        }
        return $map;
    }

    private function resolveModuleFromClass(string $className): string
    {
        $parts = explode('\\', ltrim($className, '\\'));
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        return 'unknown';
    }

    /**
     * C.4: Generate a lookup key from a canonical entry for seed matching.
     */
    private function seedKey(array $canonicalEntry): string
    {
        $type = $canonicalEntry['type'] ?? '';
        return match ($type) {
            'route' => 'route:' . ($canonicalEntry['area'] ?? '') . ':' . ($canonicalEntry['route_id'] ?? ''),
            'cron' => 'cron:' . ($canonicalEntry['group'] ?? '') . ':' . ($canonicalEntry['cron_id'] ?? ''),
            'cli' => 'cli:' . ($canonicalEntry['command_name'] ?? ''),
            'api' => 'api:' . ($canonicalEntry['method'] ?? '') . ':' . ($canonicalEntry['path'] ?? ''),
            default => 'unknown:' . json_encode($canonicalEntry),
        };
    }

    /**
     * C.4: Match an execution path to a scenario seed.
     * Uses class-based reverse lookups for cron/cli, route_id for controllers.
     */
    private function matchSeed(array $path, array $seedMap, array $cronClassToSeed = [], array $cliClassToSeed = []): ?array
    {
        $type = $path['type'] ?? '';
        $entryPoint = $path['entry_point'] ?? '';
        $entryClass = strtolower(ltrim(explode('::', $entryPoint)[0], '\\'));

        // Class-based matching for cron and CLI
        if ($type === 'cron' && $entryClass !== '' && isset($cronClassToSeed[$entryClass])) {
            return $cronClassToSeed[$entryClass];
        }
        if ($type === 'cli_command' && $entryClass !== '' && isset($cliClassToSeed[$entryClass])) {
            return $cliClassToSeed[$entryClass];
        }

        // Route-based matching for controllers (try route_id from scenario name)
        if ($type === 'controller') {
            $area = $path['area'] ?? 'frontend';
            // Scenario format: area.controller.path.parts → try route_id: area/path/parts
            $scenario = $path['scenario'] ?? '';
            $parts = explode('.', $scenario);
            if (count($parts) >= 3) {
                // Skip area and 'controller' prefix, rejoin as route_id
                $routeParts = array_slice($parts, 1); // remove area prefix
                if (($routeParts[0] ?? '') === 'controller') {
                    $routeParts = array_slice($routeParts, 1);
                }
                $routeId = $area . '/' . implode('/', $routeParts);
                $key = 'route:' . $area . ':' . $routeId;
                if (isset($seedMap[$key])) {
                    return $seedMap[$key];
                }
            }
        }

        return null;
    }

    /**
     * C.4: Infer the step_kind for an entry point.
     * step_kind describes the execution model of the entry point.
     */
    private function inferStepKind(array $path): string
    {
        $type = $path['type'] ?? '';
        return match ($type) {
            'controller' => 'http_request',
            'cron' => 'scheduled_task',
            'cli' => 'cli_command',
            'api' => 'api_call',
            default => 'unknown',
        };
    }
}

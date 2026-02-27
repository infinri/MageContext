<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Extractor 5 — Plugin Seam + Timing Extractor.
 *
 * Extracts:
 * - For every pluggable method: what existing plugins fire, in what order,
 *   what state they may modify, and what the hook type (before/after/around) implies
 * - Side-effect warnings: plugins that modify arguments, return values, or call proceed()
 * - Timing annotations: sort order sequences showing execution order
 * - Recommendations: which hook type is safest for new customizations
 *
 * AI failure mode prevented:
 * Writing an after-plugin that tries to modify a return value that an around-plugin
 * has already replaced, or adding a before-plugin that conflicts with existing sort order.
 */
class PluginSeamTimingExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'plugin_seam_timing';
    }

    public function getDescription(): string
    {
        return 'Analyzes plugin seams with timing, state-modification warnings, and hook-type recommendations';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // 1. Collect all plugin declarations from di.xml
        $pluginDeclarations = $this->collectPluginDeclarations($repoPath, $scopes);

        // 2. Analyze each plugin's PHP source for side effects
        $pluginDeclarations = $this->analyzePluginSources($repoPath, $pluginDeclarations);

        // 3. Build per-method seam view with timing
        $seams = $this->buildSeamView($pluginDeclarations);

        // 4. Generate recommendations for each seam
        $seams = $this->generateRecommendations($seams);

        // 5. Find high-risk seams (deep chains, around-plugin conflicts)
        $highRiskSeams = array_filter($seams, fn($s) => ($s['risk_level'] ?? 'low') !== 'low');
        usort($highRiskSeams, fn($a, $b) => ($b['risk_score'] ?? 0) <=> ($a['risk_score'] ?? 0));

        // 6. Sort for determinism
        usort($seams, fn($a, $b) => strcmp($a['seam_id'], $b['seam_id']));

        // Summary
        $totalPlugins = count($pluginDeclarations);
        $aroundCount = count(array_filter($pluginDeclarations, fn($p) => !empty($p['around_methods'])));
        $beforeCount = count(array_filter($pluginDeclarations, fn($p) => !empty($p['before_methods'])));
        $afterCount = count(array_filter($pluginDeclarations, fn($p) => !empty($p['after_methods'])));

        return [
            'seams' => $seams,
            'high_risk_seams' => array_values($highRiskSeams),
            'plugin_declarations' => $pluginDeclarations,
            'summary' => [
                'total_seams' => count($seams),
                'total_plugin_declarations' => $totalPlugins,
                'plugins_with_around' => $aroundCount,
                'plugins_with_before' => $beforeCount,
                'plugins_with_after' => $afterCount,
                'high_risk_seams' => count($highRiskSeams),
                'by_module' => $this->countByField($pluginDeclarations, 'module'),
            ],
        ];
    }

    /**
     * Collect all plugin declarations from di.xml across all scopes.
     */
    private function collectPluginDeclarations(string $repoPath, array $scopes): array
    {
        $plugins = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $diScope = $this->detectDiScope($file->getRelativePathname());
                $ownerModule = $this->resolveModuleFromFile($file->getRealPath());

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    $this->warnInvalidXml($fileId, 'di.xml');
                    continue;
                }

                foreach ($xml->type ?? [] as $typeNode) {
                    $targetClass = IdentityResolver::normalizeFqcn((string) ($typeNode['name'] ?? ''));
                    if ($targetClass === '') {
                        continue;
                    }

                    foreach ($typeNode->plugin ?? [] as $pluginNode) {
                        $pluginName = (string) ($pluginNode['name'] ?? '');
                        $pluginClass = IdentityResolver::normalizeFqcn((string) ($pluginNode['type'] ?? ''));
                        $sortOrder = (int) ($pluginNode['sortOrder'] ?? 0);
                        $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                        if ($pluginClass === '' || $pluginName === '') {
                            continue;
                        }

                        $plugins[] = [
                            'plugin_name' => $pluginName,
                            'plugin_class' => $pluginClass,
                            'target_class' => $targetClass,
                            'sort_order' => $sortOrder,
                            'disabled' => $disabled,
                            'scope' => $diScope,
                            'module' => $ownerModule,
                            'source_file' => $fileId,
                            'before_methods' => [],
                            'after_methods' => [],
                            'around_methods' => [],
                            'side_effects' => [],
                            'evidence' => [
                                Evidence::fromXml(
                                    $fileId,
                                    "plugin {$pluginName} on {$targetClass} sort={$sortOrder} scope={$diScope}"
                                )->toArray(),
                            ],
                        ];
                    }
                }
            }
        }

        return $plugins;
    }

    /**
     * Analyze each plugin's PHP source to discover methods and side effects.
     */
    private function analyzePluginSources(string $repoPath, array $plugins): array
    {
        foreach ($plugins as &$plugin) {
            $pluginClass = $plugin['plugin_class'];

            $filePath = $this->context !== null
                ? $this->moduleResolver()->resolveClassFile($pluginClass)
                : null;

            if ($filePath === null || !is_file($filePath)) {
                continue;
            }

            $content = @file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            $fileId = $this->fileId($filePath, $repoPath);

            // Extract plugin methods by prefix
            $plugin['before_methods'] = $this->extractPluginMethods($content, 'before');
            $plugin['after_methods'] = $this->extractPluginMethods($content, 'after');
            $plugin['around_methods'] = $this->extractPluginMethods($content, 'around');

            // Analyze side effects for each method
            $plugin['side_effects'] = $this->analyzeSideEffects($content, $plugin, $fileId);
        }
        unset($plugin);

        return $plugins;
    }

    /**
     * Extract plugin methods by prefix (before, after, around).
     *
     * @return array<array{method: string, target_method: string, line: int}>
     */
    private function extractPluginMethods(string $content, string $prefix): array
    {
        $methods = [];

        $pattern = '/(?:public\s+)?function\s+(' . $prefix . '(\w+))\s*\(/m';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $fullMethodName = $match[1][0];
                $targetMethodRaw = $match[2][0];
                // Convert PascalCase target back to camelCase
                $targetMethod = lcfirst($targetMethodRaw);

                $line = substr_count(substr($content, 0, $match[0][1]), "\n") + 1;

                $methods[] = [
                    'method' => $fullMethodName,
                    'target_method' => $targetMethod,
                    'line' => $line,
                ];
            }
        }

        return $methods;
    }

    /**
     * Analyze side effects of plugin methods.
     */
    private function analyzeSideEffects(string $content, array $plugin, string $fileId): array
    {
        $sideEffects = [];

        // Check around methods for proceed() handling
        foreach ($plugin['around_methods'] as $method) {
            $methodContent = $this->extractMethodBody($content, $method['method']);
            if ($methodContent === null) {
                continue;
            }

            $effects = [];

            // Does it call $proceed?
            $callsProceed = str_contains($methodContent, '$proceed');
            if (!$callsProceed) {
                $effects[] = [
                    'type' => 'skips_proceed',
                    'severity' => 'critical',
                    'message' => "Around plugin {$method['method']} does NOT call \$proceed — original method is completely replaced",
                ];
            }

            // Does it modify $proceed arguments?
            if (preg_match('/\$proceed\s*\((?!\s*\.\.\.\$)/', $methodContent)) {
                $effects[] = [
                    'type' => 'modifies_arguments',
                    'severity' => 'high',
                    'message' => "Around plugin {$method['method']} may pass different arguments to \$proceed",
                ];
            }

            // Does it modify the return value?
            if (preg_match('/\$result\s*=\s*\$proceed/', $methodContent) && preg_match('/return\s+(?!\$result)/', $methodContent)) {
                $effects[] = [
                    'type' => 'modifies_return',
                    'severity' => 'medium',
                    'message' => "Around plugin {$method['method']} may modify the return value from \$proceed",
                ];
            }

            // Does it perform state mutations (setData, save, etc.)?
            if (preg_match('/->(?:setData|save|delete|setState|setStatus)\s*\(/', $methodContent)) {
                $effects[] = [
                    'type' => 'state_mutation',
                    'severity' => 'high',
                    'message' => "Around plugin {$method['method']} performs state mutations (save/setData/etc.)",
                ];
            }

            foreach ($effects as $effect) {
                $effect['plugin_class'] = $plugin['plugin_class'];
                $effect['hook_type'] = 'around';
                $effect['target_method'] = $method['target_method'];
                $effect['evidence'] = Evidence::fromPhpAst(
                    $fileId,
                    $method['line'],
                    null,
                    $effect['message']
                )->toArray();
                $sideEffects[] = $effect;
            }
        }

        // Check before methods for argument modification
        foreach ($plugin['before_methods'] as $method) {
            $methodContent = $this->extractMethodBody($content, $method['method']);
            if ($methodContent === null) {
                continue;
            }

            // Before plugins return modified arguments
            if (preg_match('/return\s+\[/', $methodContent)) {
                $sideEffects[] = [
                    'type' => 'modifies_arguments',
                    'severity' => 'medium',
                    'hook_type' => 'before',
                    'plugin_class' => $plugin['plugin_class'],
                    'target_method' => $method['target_method'],
                    'message' => "Before plugin {$method['method']} modifies method arguments",
                    'evidence' => Evidence::fromPhpAst(
                        $fileId,
                        $method['line'],
                        null,
                        "argument modification in before plugin"
                    )->toArray(),
                ];
            }

            // State mutations in before plugins
            if (preg_match('/->(?:setData|save|delete|setState|setStatus)\s*\(/', $methodContent)) {
                $sideEffects[] = [
                    'type' => 'state_mutation',
                    'severity' => 'high',
                    'hook_type' => 'before',
                    'plugin_class' => $plugin['plugin_class'],
                    'target_method' => $method['target_method'],
                    'message' => "Before plugin {$method['method']} performs state mutations before the original method",
                    'evidence' => Evidence::fromPhpAst(
                        $fileId,
                        $method['line'],
                        null,
                        "state mutation in before plugin"
                    )->toArray(),
                ];
            }
        }

        // Check after methods for return value modification
        foreach ($plugin['after_methods'] as $method) {
            $methodContent = $this->extractMethodBody($content, $method['method']);
            if ($methodContent === null) {
                continue;
            }

            // After plugins that don't return $result directly
            if (preg_match('/return\s+(?!\$result\s*;)/', $methodContent)) {
                $sideEffects[] = [
                    'type' => 'modifies_return',
                    'severity' => 'medium',
                    'hook_type' => 'after',
                    'plugin_class' => $plugin['plugin_class'],
                    'target_method' => $method['target_method'],
                    'message' => "After plugin {$method['method']} modifies the return value",
                    'evidence' => Evidence::fromPhpAst(
                        $fileId,
                        $method['line'],
                        null,
                        "return modification in after plugin"
                    )->toArray(),
                ];
            }
        }

        return $sideEffects;
    }

    /**
     * Extract a method body from source content by method name.
     */
    private function extractMethodBody(string $content, string $methodName): ?string
    {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $i = $start;
        $len = strlen($content);

        while ($i < $len && $depth > 0) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
            }
            $i++;
        }

        return substr($content, $start, $i - $start - 1);
    }

    /**
     * Build per-method seam view: aggregate all plugins per target::method with timing.
     */
    private function buildSeamView(array $pluginDeclarations): array
    {
        // Group by target_class::target_method
        $seamMap = [];

        foreach ($pluginDeclarations as $plugin) {
            if ($plugin['disabled']) {
                continue;
            }

            $targetClass = $plugin['target_class'];

            // Collect all target methods from this plugin's before/after/around methods
            $allTargetMethods = [];
            foreach ($plugin['before_methods'] as $m) {
                $allTargetMethods[$m['target_method']]['before'][] = $plugin;
            }
            foreach ($plugin['after_methods'] as $m) {
                $allTargetMethods[$m['target_method']]['after'][] = $plugin;
            }
            foreach ($plugin['around_methods'] as $m) {
                $allTargetMethods[$m['target_method']]['around'][] = $plugin;
            }

            foreach ($allTargetMethods as $targetMethod => $hooks) {
                $seamKey = $targetClass . '::' . $targetMethod;
                if (!isset($seamMap[$seamKey])) {
                    $seamMap[$seamKey] = [
                        'seam_id' => IdentityResolver::methodId($targetClass, $targetMethod),
                        'target_class' => $targetClass,
                        'target_method' => $targetMethod,
                        'before_plugins' => [],
                        'around_plugins' => [],
                        'after_plugins' => [],
                        'side_effects' => [],
                    ];
                }

                foreach ($hooks['before'] ?? [] as $p) {
                    $seamMap[$seamKey]['before_plugins'][] = $this->buildPluginEntry($p, 'before', $targetMethod);
                }
                foreach ($hooks['around'] ?? [] as $p) {
                    $seamMap[$seamKey]['around_plugins'][] = $this->buildPluginEntry($p, 'around', $targetMethod);
                }
                foreach ($hooks['after'] ?? [] as $p) {
                    $seamMap[$seamKey]['after_plugins'][] = $this->buildPluginEntry($p, 'after', $targetMethod);
                }

                // Collect side effects
                foreach ($plugin['side_effects'] as $se) {
                    if ($se['target_method'] === $targetMethod) {
                        $seamMap[$seamKey]['side_effects'][] = $se;
                    }
                }
            }
        }

        // Sort plugins within each seam by sort_order
        foreach ($seamMap as &$seam) {
            usort($seam['before_plugins'], fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
            usort($seam['around_plugins'], fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
            usort($seam['after_plugins'], fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

            // Build execution sequence
            $seam['execution_sequence'] = $this->buildExecutionSequence($seam);

            // Compute plugin depth and risk
            $totalPlugins = count($seam['before_plugins']) + count($seam['around_plugins']) + count($seam['after_plugins']);
            $seam['total_plugins'] = $totalPlugins;
            $seam['has_around'] = !empty($seam['around_plugins']);
            $seam['has_multiple_around'] = count($seam['around_plugins']) > 1;
        }
        unset($seam);

        return array_values($seamMap);
    }

    /**
     * Build a plugin entry for the seam view.
     */
    private function buildPluginEntry(array $plugin, string $hookType, string $targetMethod): array
    {
        return [
            'plugin_class' => $plugin['plugin_class'],
            'plugin_name' => $plugin['plugin_name'],
            'hook_type' => $hookType,
            'sort_order' => $plugin['sort_order'],
            'scope' => $plugin['scope'],
            'module' => $plugin['module'],
            'cross_module' => IdentityResolver::isCrossModule($plugin['plugin_class'], $plugin['target_class']),
            'evidence' => $plugin['evidence'],
        ];
    }

    /**
     * Build the execution sequence showing the order plugins fire.
     *
     * Magento execution order:
     * 1. Before plugins (sorted by sort_order)
     * 2. Around plugins (sorted by sort_order, nested: outermost wraps innermost)
     *    - Around: before $proceed
     *    - Original method (or next around)
     *    - Around: after $proceed
     * 3. After plugins (sorted by sort_order)
     */
    private function buildExecutionSequence(array $seam): array
    {
        $sequence = [];
        $step = 1;

        // Before plugins execute first
        foreach ($seam['before_plugins'] as $p) {
            $sequence[] = [
                'step' => $step++,
                'phase' => 'before',
                'plugin' => $p['plugin_class'],
                'sort_order' => $p['sort_order'],
                'note' => 'Executes before the original method. Can modify arguments.',
            ];
        }

        // Around plugins wrap the original (first sort_order = outermost)
        if (!empty($seam['around_plugins'])) {
            foreach ($seam['around_plugins'] as $i => $p) {
                $sequence[] = [
                    'step' => $step++,
                    'phase' => 'around_before_proceed',
                    'plugin' => $p['plugin_class'],
                    'sort_order' => $p['sort_order'],
                    'note' => $i === 0
                        ? 'Outermost around plugin. Code before $proceed() runs first.'
                        : 'Inner around plugin. Wrapped by higher sort-order plugins.',
                ];
            }

            $sequence[] = [
                'step' => $step++,
                'phase' => 'original_method',
                'plugin' => null,
                'sort_order' => null,
                'note' => 'Original method executes (if all around plugins call $proceed).',
            ];

            // After proceed, around plugins unwind in reverse
            foreach (array_reverse($seam['around_plugins']) as $p) {
                $sequence[] = [
                    'step' => $step++,
                    'phase' => 'around_after_proceed',
                    'plugin' => $p['plugin_class'],
                    'sort_order' => $p['sort_order'],
                    'note' => 'Code after $proceed() in around plugin.',
                ];
            }
        } else {
            $sequence[] = [
                'step' => $step++,
                'phase' => 'original_method',
                'plugin' => null,
                'sort_order' => null,
                'note' => 'Original method executes (no around plugins).',
            ];
        }

        // After plugins execute last
        foreach ($seam['after_plugins'] as $p) {
            $sequence[] = [
                'step' => $step++,
                'phase' => 'after',
                'plugin' => $p['plugin_class'],
                'sort_order' => $p['sort_order'],
                'note' => 'Executes after the original method. Can modify return value.',
            ];
        }

        return $sequence;
    }

    /**
     * Generate recommendations for each seam.
     */
    private function generateRecommendations(array $seams): array
    {
        foreach ($seams as &$seam) {
            $recommendations = [];
            $riskScore = 0.0;
            $riskLevel = 'low';

            // Multiple around plugins = high risk
            if ($seam['has_multiple_around']) {
                $riskScore += 0.4;
                $recommendations[] = [
                    'type' => 'warning',
                    'message' => 'Multiple around plugins on this method. '
                        . 'The execution order depends on sort_order. '
                        . 'Prefer before/after plugins when possible to avoid $proceed chain complexity.',
                ];
            }

            // Any around plugin
            if ($seam['has_around']) {
                $riskScore += 0.2;
                $recommendations[] = [
                    'type' => 'caution',
                    'message' => 'Around plugin(s) present. New plugins should use before/after hooks '
                        . 'unless you need to conditionally prevent the original method from executing.',
                ];
            }

            // Critical side effects
            $criticalEffects = array_filter($seam['side_effects'], fn($se) => $se['severity'] === 'critical');
            if (!empty($criticalEffects)) {
                $riskScore += 0.3;
                foreach ($criticalEffects as $se) {
                    $recommendations[] = [
                        'type' => 'critical',
                        'message' => $se['message'],
                    ];
                }
            }

            // High-severity side effects
            $highEffects = array_filter($seam['side_effects'], fn($se) => $se['severity'] === 'high');
            if (!empty($highEffects)) {
                $riskScore += 0.1 * count($highEffects);
            }

            // Deep plugin chain
            if ($seam['total_plugins'] > 5) {
                $riskScore += 0.2;
                $recommendations[] = [
                    'type' => 'warning',
                    'message' => "Deep plugin chain ({$seam['total_plugins']} plugins). "
                        . 'Consider whether a preference override would be simpler and safer.',
                ];
            }

            // Suggest safest hook type for new customization
            if (empty($seam['around_plugins'])) {
                $recommendations[] = [
                    'type' => 'recommendation',
                    'message' => 'No around plugins present. Safe to add before/after plugins. '
                        . 'Use before to modify arguments, after to modify return value.',
                ];
            } else {
                $recommendations[] = [
                    'type' => 'recommendation',
                    'message' => 'Around plugin(s) already present. '
                        . 'A before-plugin is safest for pre-processing. '
                        . 'An after-plugin works for post-processing but receives the around-modified return value.',
                ];
            }

            $riskScore = round(min(1.0, $riskScore), 3);
            if ($riskScore >= 0.6) {
                $riskLevel = 'high';
            } elseif ($riskScore >= 0.3) {
                $riskLevel = 'medium';
            }

            $seam['risk_score'] = $riskScore;
            $seam['risk_level'] = $riskLevel;
            $seam['recommendations'] = $recommendations;
        }
        unset($seam);

        return $seams;
    }

    /**
     * Detect DI scope from relative file path.
     */
    private function detectDiScope(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $scopes = ['frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab'];

        foreach ($scopes as $scope) {
            if (str_contains($normalized, '/etc/' . $scope . '/di.xml')) {
                return $scope;
            }
        }

        return 'global';
    }
}

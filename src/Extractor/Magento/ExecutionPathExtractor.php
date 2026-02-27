<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use PhpParser\Node;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Execution path reconstruction with evidence.
 */
class ExecutionPathExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'execution_paths';
    }

    public function getDescription(): string
    {
        return 'Reconstructs execution paths from entry points through DI, plugins, and observers';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // 1. Discover entry points
        $entryPoints = $this->discoverEntryPoints($repoPath, $scopes, $parser);

        // 2. Load DI preferences for resolution
        $preferences = $this->loadPreferences($repoPath, $scopes);

        // 3. Load plugin chains
        $pluginChains = $this->loadPluginChains($repoPath, $scopes);

        // 4. Load observer event map
        $observerMap = $this->loadObserverMap($repoPath, $scopes);

        // 5. Build execution paths for each entry point
        $paths = [];
        foreach ($entryPoints as $entry) {
            $path = $this->buildExecutionPath($entry, $preferences, $pluginChains, $observerMap);
            $paths[] = $path;
        }

        // Sort by complexity (plugin_depth + observer_count) descending
        usort($paths, function ($a, $b) {
            $aComplexity = ($a['plugin_depth'] ?? 0) + ($a['observer_count'] ?? 0);
            $bComplexity = ($b['plugin_depth'] ?? 0) + ($b['observer_count'] ?? 0);
            return $bComplexity <=> $aComplexity;
        });

        return [
            'paths' => $paths,
            'summary' => [
                'total_entry_points' => count($entryPoints),
                'total_paths' => count($paths),
                'by_type' => $this->countByField($entryPoints, 'type'),
                'avg_plugin_depth' => $this->avgField($paths, 'plugin_depth'),
                'avg_observer_count' => $this->avgField($paths, 'observer_count'),
                'max_plugin_depth' => $this->maxField($paths, 'plugin_depth'),
            ],
        ];
    }

    /**
     * Discover all entry points: Controllers, REST routes, CLI commands, Cron jobs.
     */
    private function discoverEntryPoints(string $repoPath, array $scopes, $parser): array
    {
        $entryPoints = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            // Controllers
            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('/Controller/')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                // Detect area from path
                $area = 'frontend';
                if (str_contains($relativePath, '/Adminhtml/') || str_contains($relativePath, '/adminhtml/')) {
                    $area = 'adminhtml';
                }

                $fId = $this->fileId($file->getRealPath(), $repoPath);
                $entryPoints[] = [
                    'type' => 'controller',
                    'class' => IdentityResolver::normalizeFqcn($className),
                    'method' => 'execute',
                    'area' => $area,
                    'source_file' => $fId,
                    'module' => $this->resolveModuleFromFile($file->getRealPath()),
                    'evidence' => [Evidence::fromPhpAst($fId, 0, null, "controller entry point {$className}")->toArray()],
                ];
            }

            // CLI Commands
            $cliFinder = new Finder();
            $cliFinder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('/Console/')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($cliFinder as $file) {
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                if (!str_contains($content, 'extends Command') && !str_contains($content, 'CommandInterface')) {
                    continue;
                }

                $className = $this->extractClassName($content);
                if ($className === null) {
                    continue;
                }

                $fId = $this->fileId($file->getRealPath(), $repoPath);
                $entryPoints[] = [
                    'type' => 'cli_command',
                    'class' => IdentityResolver::normalizeFqcn($className),
                    'method' => 'execute',
                    'area' => 'cli',
                    'source_file' => $fId,
                    'module' => $this->resolveModuleFromFile($file->getRealPath()),
                    'evidence' => [Evidence::fromPhpAst($fId, 0, null, "CLI command entry point {$className}")->toArray()],
                ];
            }

            // Cron jobs (from crontab.xml)
            $cronFinder = new Finder();
            $cronFinder->files()
                ->in($scopePath)
                ->name('crontab.xml')
                ->sortByName();

            foreach ($cronFinder as $file) {
                $fId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseCrontabXml($file->getRealPath(), $fId, $declaringModule);
                foreach ($parsed as $cron) {
                    $entryPoints[] = $cron;
                }
            }
        }

        return $entryPoints;
    }

    /**
     * Parse crontab.xml for cron job entry points.
     */
    private function parseCrontabXml(string $filePath, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'crontab.xml');
            return [];
        }

        $entries = [];
        foreach ($xml->group ?? [] as $groupNode) {
            $groupName = (string) ($groupNode['id'] ?? 'default');

            foreach ($groupNode->job ?? [] as $jobNode) {
                $jobName = (string) ($jobNode['name'] ?? '');
                $instance = IdentityResolver::normalizeFqcn((string) ($jobNode['instance'] ?? ''));
                $method = (string) ($jobNode['method'] ?? 'execute');
                $schedule = '';

                if (isset($jobNode->schedule)) {
                    $schedule = trim((string) $jobNode->schedule);
                }

                if ($jobName !== '' && $instance !== '') {
                    $entries[] = [
                        'type' => 'cron',
                        'class' => $instance,
                        'method' => $method,
                        'area' => 'crontab',
                        'source_file' => $fileId,
                        'module' => $declaringModule,
                        'cron_name' => $jobName,
                        'cron_group' => $groupName,
                        'schedule' => $schedule,
                        'evidence' => [Evidence::fromXml($fileId, "cron entry point {$jobName} -> {$instance}::{$method}")->toArray()],
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Build an execution path for an entry point.
     */
    private function buildExecutionPath(
        array $entry,
        array $preferences,
        array $pluginChains,
        array $observerMap
    ): array {
        $class = $entry['class'];
        $method = $entry['method'] ?? 'execute';

        // Resolve preferences: check if the class has been replaced
        $resolvedClass = $class;
        $preferenceChain = [];
        $seen = [];
        while (isset($preferences[$resolvedClass]) && !isset($seen[$resolvedClass])) {
            $seen[$resolvedClass] = true;
            $pref = $preferences[$resolvedClass];
            $preferenceChain[] = [
                'from' => $resolvedClass,
                'to' => $pref,
            ];
            $resolvedClass = $pref;
        }

        // Find plugin chain for the target class::method
        $methodKey = $resolvedClass . '::' . $method;
        $classKey = $resolvedClass;
        $pluginsOnMethod = [];
        $pluginsOnClass = [];

        foreach ($pluginChains as $target => $plugins) {
            if ($target === $methodKey) {
                $pluginsOnMethod = $plugins;
            } elseif ($target === $classKey) {
                $pluginsOnClass = $plugins;
            }
        }

        // Combine: method-specific plugins take priority
        $applicablePlugins = !empty($pluginsOnMethod) ? $pluginsOnMethod : $pluginsOnClass;

        // Count by type
        $beforePlugins = array_filter($applicablePlugins, fn($p) => ($p['type'] ?? '') === 'before');
        $afterPlugins = array_filter($applicablePlugins, fn($p) => ($p['type'] ?? '') === 'after');
        $aroundPlugins = array_filter($applicablePlugins, fn($p) => ($p['type'] ?? '') === 'around');

        // Find observers that might be triggered
        // We look for events that are commonly dispatched by this type of entry point
        $potentialEvents = $this->inferEventsForEntryPoint($entry);
        $triggeredObservers = [];
        foreach ($potentialEvents as $eventName) {
            if (isset($observerMap[$eventName])) {
                $triggeredObservers[$eventName] = $observerMap[$eventName];
            }
        }

        $observerCount = 0;
        foreach ($triggeredObservers as $observers) {
            $observerCount += count($observers);
        }

        $scenario = strtolower($entry['area'] ?? 'global') . '.' . $this->classToScenarioName($class);

        return [
            'scenario' => $scenario,
            'entry_point' => $class . '::' . $method,
            'entry_class' => $class,
            'type' => $entry['type'],
            'area' => $entry['area'] ?? 'global',
            'module' => $entry['module'] ?? 'unknown',
            'source_file' => $entry['source_file'] ?? '',
            'resolved_class' => $resolvedClass,
            'preferences_resolved' => $preferenceChain,
            'plugin_stack' => array_values($applicablePlugins),
            'plugin_depth' => count($applicablePlugins),
            'before_plugins' => count($beforePlugins),
            'after_plugins' => count($afterPlugins),
            'around_plugins' => count($aroundPlugins),
            'triggered_observers' => $triggeredObservers,
            'observer_count' => $observerCount,
        ];
    }

    /**
     * Load DI preferences from di.xml files.
     *
     * @return array<string, string> interface => preference class
     */
    private function loadPreferences(string $repoPath, array $scopes): array
    {
        $preferences = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = (string) ($node['for'] ?? '');
                    $type = (string) ($node['type'] ?? '');
                    if ($for !== '' && $type !== '') {
                        $preferences[$for] = $type;
                    }
                }
            }
        }

        return $preferences;
    }

    /**
     * Load plugin chains from di.xml, keyed by target class.
     *
     * @return array<string, array>
     */
    private function loadPluginChains(string $repoPath, array $scopes): array
    {
        $chains = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//type') ?: [] as $typeNode) {
                    $targetClass = (string) ($typeNode['name'] ?? '');
                    if ($targetClass === '') {
                        continue;
                    }

                    foreach ($typeNode->plugin ?? [] as $pluginNode) {
                        $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';
                        if ($disabled) {
                            continue;
                        }

                        $pluginName = (string) ($pluginNode['name'] ?? '');
                        $pluginClass = (string) ($pluginNode['type'] ?? '');
                        $sortOrder = ($pluginNode['sortOrder'] ?? null) !== null
                            ? (int) $pluginNode['sortOrder']
                            : null;

                        if ($pluginName !== '') {
                            $chains[$targetClass][] = [
                                'plugin_name' => $pluginName,
                                'plugin_class' => $pluginClass,
                                'sort_order' => $sortOrder,
                                'module' => IdentityResolver::moduleIdFromClass($pluginClass),
                            ];
                        }
                    }
                }
            }
        }

        // Sort each chain by sort_order
        foreach ($chains as &$chain) {
            usort($chain, function ($a, $b) {
                $aOrder = $a['sort_order'] ?? PHP_INT_MAX;
                $bOrder = $b['sort_order'] ?? PHP_INT_MAX;
                return $aOrder <=> $bOrder;
            });
        }
        unset($chain);

        return $chains;
    }

    /**
     * Load observer map from events.xml, keyed by event name.
     *
     * @return array<string, array>
     */
    private function loadObserverMap(string $repoPath, array $scopes): array
    {
        $map = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('events.xml')->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->event ?? [] as $eventNode) {
                    $eventName = (string) ($eventNode['name'] ?? '');
                    if ($eventName === '') {
                        continue;
                    }

                    foreach ($eventNode->observer ?? [] as $observerNode) {
                        $disabled = strtolower((string) ($observerNode['disabled'] ?? 'false')) === 'true';
                        if ($disabled) {
                            continue;
                        }

                        $observerClass = (string) ($observerNode['instance'] ?? '');
                        $observerName = (string) ($observerNode['name'] ?? '');

                        if ($observerName !== '') {
                            $map[$eventName][] = [
                                'observer_name' => $observerName,
                                'observer_class' => $observerClass,
                                'module' => IdentityResolver::moduleIdFromClass($observerClass),
                            ];
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Infer which events an entry point might dispatch based on its type and class name.
     *
     * @return array<string>
     */
    private function inferEventsForEntryPoint(array $entry): array
    {
        $events = [];
        $type = $entry['type'] ?? '';
        $class = $entry['class'] ?? '';

        // Common event patterns based on controller/action type
        $classLower = strtolower($class);

        if (str_contains($classLower, 'cart') && str_contains($classLower, 'add')) {
            $events[] = 'checkout_cart_product_add_after';
            $events[] = 'checkout_cart_add_product_complete';
            $events[] = 'sales_quote_item_set_product';
        } elseif (str_contains($classLower, 'cart')) {
            $events[] = 'checkout_cart_save_after';
            $events[] = 'checkout_cart_update_items_after';
        } elseif (str_contains($classLower, 'order') && str_contains($classLower, 'place')) {
            $events[] = 'checkout_submit_all_after';
            $events[] = 'sales_order_place_after';
            $events[] = 'sales_model_service_quote_submit_success';
        } elseif (str_contains($classLower, 'order') && str_contains($classLower, 'save')) {
            $events[] = 'sales_order_save_after';
        } elseif (str_contains($classLower, 'product') && str_contains($classLower, 'save')) {
            $events[] = 'catalog_product_save_after';
            $events[] = 'catalog_product_save_commit_after';
        } elseif (str_contains($classLower, 'customer') && str_contains($classLower, 'create')) {
            $events[] = 'customer_register_success';
            $events[] = 'customer_save_after';
        } elseif (str_contains($classLower, 'customer') && str_contains($classLower, 'login')) {
            $events[] = 'customer_login';
            $events[] = 'customer_customer_authenticated';
        }

        // Generic controller dispatch event
        if ($type === 'controller') {
            $events[] = 'controller_action_predispatch';
            $events[] = 'controller_action_postdispatch';
        }

        return $events;
    }

    /**
     * Extract FQCN from PHP file content.
     */
    private function extractClassName(string $content): ?string
    {
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $content, $nsMatch)) {
            $namespace = $nsMatch[1];
        }

        if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            $class = $classMatch[1];
        }

        if ($namespace !== '' && $class !== '') {
            return $namespace . '\\' . $class;
        }

        return null;
    }

    /**
     * Convert a class name to a scenario-style name.
     * e.g., SecondSwing\Checkout\Controller\Cart\Add => checkout.cart.add
     */
    private function classToScenarioName(string $className): string
    {
        $parts = explode('\\', ltrim($className, '\\'));
        // Remove vendor and module name (first 2 parts)
        $relevant = array_slice($parts, 2);
        $name = implode('.', array_map('strtolower', $relevant));
        return $name ?: 'unknown';
    }

    private function avgField(array $paths, string $field): float
    {
        if (empty($paths)) {
            return 0.0;
        }
        $sum = array_sum(array_column($paths, $field));
        return round($sum / count($paths), 2);
    }

    private function maxField(array $paths, string $field): int
    {
        if (empty($paths)) {
            return 0;
        }
        return (int) max(array_column($paths, $field));
    }
}

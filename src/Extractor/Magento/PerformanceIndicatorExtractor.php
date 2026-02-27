<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;

/**
 * Performance risk indicators with evidence and 'why this is risky'.
 */
class PerformanceIndicatorExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'performance';
    }

    public function getDescription(): string
    {
        return 'Detects performance risk indicators: deep plugin stacks, high observer counts, layout merge depth';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $indicators = [];

        // 1. Deep plugin stacks (>3 plugins on same target)
        $deepPlugins = $this->findDeepPluginStacks($repoPath, $scopes);
        foreach ($deepPlugins as $dp) {
            $indicators[] = [
                'type' => 'deep_plugin_stack',
                'severity' => $dp['depth'] > 5 ? 'high' : 'medium',
                'target' => $dp['target'],
                'depth' => $dp['depth'],
                'why_risky' => "Deep plugin stack ({$dp['depth']} plugins) on same target creates cumulative latency and unpredictable execution order.",
                'plugins' => $dp['plugins'],
                'evidence' => [Evidence::fromInference("{$dp['depth']} plugins on {$dp['target']}")->toArray()],
            ];
        }

        // 2. High observer counts per event
        $highObserverEvents = $this->findHighObserverEvents($repoPath, $scopes);
        foreach ($highObserverEvents as $hoe) {
            $indicators[] = [
                'type' => 'high_observer_count',
                'severity' => $hoe['count'] > 10 ? 'high' : 'medium',
                'event' => $hoe['event'],
                'observer_count' => $hoe['count'],
                'why_risky' => "Event '{$hoe['event']}' has {$hoe['count']} observers. Each observer runs synchronously, compounding latency.",
                'observers' => $hoe['observers'],
                'evidence' => [Evidence::fromInference("{$hoe['count']} observers on event {$hoe['event']}")->toArray()],
            ];
        }

        // 3. Layout merge depth (many layout handles overriding same block/container)
        $layoutDepth = $this->findLayoutMergeDepth($repoPath, $scopes);
        foreach ($layoutDepth as $ld) {
            $indicators[] = [
                'type' => 'layout_merge_depth',
                'severity' => $ld['override_count'] > 5 ? 'high' : 'medium',
                'handle' => $ld['handle'],
                'override_count' => $ld['override_count'],
                'why_risky' => "Layout element '{$ld['handle']}' overridden by {$ld['module_count']} modules. Layout merge complexity grows combinatorially.",
                'sources' => $ld['sources'],
                'evidence' => [Evidence::fromInference("{$ld['override_count']} overrides on layout element {$ld['handle']}")->toArray()],
            ];
        }

        // Sort by severity then by depth/count
        $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($indicators, function ($a, $b) use ($severityOrder) {
            $sev = ($severityOrder[$a['severity']] ?? 99) <=> ($severityOrder[$b['severity']] ?? 99);
            if ($sev !== 0) {
                return $sev;
            }
            $aVal = $a['depth'] ?? $a['observer_count'] ?? $a['override_count'] ?? 0;
            $bVal = $b['depth'] ?? $b['observer_count'] ?? $b['override_count'] ?? 0;
            return $bVal <=> $aVal;
        });

        return [
            'indicators' => $indicators,
            'summary' => [
                'total_indicators' => count($indicators),
                'deep_plugin_stacks' => count($deepPlugins),
                'high_observer_events' => count($highObserverEvents),
                'layout_merge_concerns' => count($layoutDepth),
                'by_severity' => $this->countByField($indicators, 'severity'),
            ],
        ];
    }

    /**
     * Find target classes with deep plugin stacks (>3).
     */
    private function findDeepPluginStacks(string $repoPath, array $scopes): array
    {
        $targetPlugins = []; // targetClass => [plugins]

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

                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());

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
                            $targetPlugins[$targetClass][] = [
                                'plugin_name' => $pluginName,
                                'plugin_class' => $pluginClass,
                                'sort_order' => $sortOrder,
                                'source_file' => $relativePath,
                            ];
                        }
                    }
                }
            }
        }

        $deep = [];
        foreach ($targetPlugins as $target => $plugins) {
            if (count($plugins) > 3) {
                // Sort by sort_order
                usort($plugins, function ($a, $b) {
                    $aOrder = $a['sort_order'] ?? PHP_INT_MAX;
                    $bOrder = $b['sort_order'] ?? PHP_INT_MAX;
                    return $aOrder <=> $bOrder;
                });

                $deep[] = [
                    'target' => $target,
                    'depth' => count($plugins),
                    'plugins' => $plugins,
                ];
            }
        }

        usort($deep, fn($a, $b) => $b['depth'] <=> $a['depth']);

        return $deep;
    }

    /**
     * Find events with high observer counts (>3).
     */
    private function findHighObserverEvents(string $repoPath, array $scopes): array
    {
        $eventObservers = []; // event => [observers]

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

                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());

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

                        $observerName = (string) ($observerNode['name'] ?? '');
                        $observerClass = (string) ($observerNode['instance'] ?? '');

                        if ($observerName !== '') {
                            $eventObservers[$eventName][] = [
                                'observer_name' => $observerName,
                                'observer_class' => $observerClass,
                                'source_file' => $relativePath,
                            ];
                        }
                    }
                }
            }
        }

        $high = [];
        foreach ($eventObservers as $event => $observers) {
            if (count($observers) > 3) {
                $high[] = [
                    'event' => $event,
                    'count' => count($observers),
                    'observers' => $observers,
                ];
            }
        }

        usort($high, fn($a, $b) => $b['count'] <=> $a['count']);

        return $high;
    }

    /**
     * Find layout blocks/containers that are overridden by many modules.
     */
    private function findLayoutMergeDepth(string $repoPath, array $scopes): array
    {
        $blockOverrides = []; // block/container name => [source files]

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.xml')
                ->path('/layout/')
                ->sortByName();

            foreach ($finder as $file) {
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());

                // Find referenceBlock and referenceContainer
                foreach ($xml->xpath('//referenceBlock') ?: [] as $node) {
                    $name = (string) ($node['name'] ?? '');
                    if ($name !== '') {
                        $blockOverrides[$name][] = $relativePath;
                    }
                }

                foreach ($xml->xpath('//referenceContainer') ?: [] as $node) {
                    $name = (string) ($node['name'] ?? '');
                    if ($name !== '') {
                        $blockOverrides[$name][] = $relativePath;
                    }
                }
            }
        }

        $deep = [];
        foreach ($blockOverrides as $handle => $sources) {
            // Only flag if multiple different modules override the same block
            $modules = array_unique(array_map(fn($s) => $this->moduleIdFromPath($s), $sources));
            if (count($modules) > 2) {
                $deep[] = [
                    'handle' => $handle,
                    'override_count' => count($sources),
                    'module_count' => count($modules),
                    'sources' => array_unique($sources),
                ];
            }
        }

        usort($deep, fn($a, $b) => $b['override_count'] <=> $a['override_count']);

        return $deep;
    }

}

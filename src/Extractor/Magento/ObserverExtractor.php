<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec \u00a73.2C: Event graph.
 *
 * For every event, produces:
 * - event_id, declared_by (module owning events.xml)
 * - listeners with observer class, method (execute), evidence
 * - cross-module ratio, risk score
 * - top_impacted_modules list
 */
class ObserverExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'event_graph';
    }

    public function getDescription(): string
    {
        return 'Extracts event/observer graph with evidence and cross-module risk scoring';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $observers = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('events.xml')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $eventScope = $this->detectScope($file->getRelativePathname());
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseEventsXml($file->getRealPath(), $repoPath, $eventScope, $fileId, $declaringModule);
                foreach ($parsed as $observer) {
                    $observers[] = $observer;
                }
            }
        }

        // Build event-centric view with risk scoring
        $eventGraph = $this->buildEventGraph($observers);

        // Identify high-risk events
        $eventFanoutThreshold = $this->context
            ? ($this->config()->getThreshold('event_fanout') ?? 10)
            : 10;
        $highRiskEvents = array_filter($eventGraph, fn($e) => $e['risk_score'] >= 0.7);
        usort($highRiskEvents, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        // Top impacted modules: modules that listen to the most events
        $topImpacted = $this->computeTopImpactedModules($observers);

        return [
            'observers' => $observers,
            'event_graph' => $eventGraph,
            'high_risk_events' => array_values($highRiskEvents),
            'top_impacted_modules' => $topImpacted,
            'summary' => [
                'total_observers' => count($observers),
                'total_events' => count($eventGraph),
                'high_risk_events' => count($highRiskEvents),
                'high_fanout_events' => count(array_filter($eventGraph, fn($e) => $e['listener_count'] > $eventFanoutThreshold)),
                'disabled_observers' => count(array_filter($observers, fn($o) => $o['disabled'])),
                'by_scope' => $this->countByField($observers, 'scope'),
                'most_observed_events' => $this->topEvents($eventGraph, 10),
            ],
        ];
    }

    private function parseEventsXml(string $filePath, string $repoPath, string $eventScope, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'events.xml');
            return [];
        }

        $observers = [];

        foreach ($xml->event ?? [] as $eventNode) {
            $eventName = (string) ($eventNode['name'] ?? '');
            if ($eventName === '') {
                continue;
            }

            $eventId = IdentityResolver::eventId($eventName);

            foreach ($eventNode->observer ?? [] as $observerNode) {
                $observerName = (string) ($observerNode['name'] ?? '');
                $observerClass = IdentityResolver::normalizeFqcn((string) ($observerNode['instance'] ?? ''));
                $disabled = strtolower((string) ($observerNode['disabled'] ?? 'false')) === 'true';

                if ($observerName === '') {
                    continue;
                }

                $observerModule = $observerClass !== '' ? $this->resolveModule($observerClass) : $declaringModule;

                $observers[] = [
                    'event_id' => $eventId,
                    'event_name' => $eventName,
                    'observer_name' => $observerName,
                    'observer_class' => $observerClass,
                    'method' => 'execute',
                    'module' => $observerModule,
                    'declared_by' => $declaringModule,
                    'scope' => $eventScope,
                    'disabled' => $disabled,
                    'evidence' => [
                        Evidence::fromXml(
                            $fileId,
                            "event '{$eventName}' observer={$observerName} instance={$observerClass}"
                        )->toArray(),
                    ],
                ];
            }
        }

        return $observers;
    }

    /**
     * Build event graph with risk scoring per event.
     *
     * Risk score = normalized(listener_count) * cross_module_ratio
     * High listener count + many cross-module listeners = high risk
     *
     * @return array<array>
     */
    private function buildEventGraph(array $observers): array
    {
        $grouped = [];
        foreach ($observers as $observer) {
            if ($observer['disabled']) {
                continue;
            }
            $event = $observer['event_name'];
            $grouped[$event][] = $observer;
        }

        // Find max listener count for normalization
        $maxListeners = 1;
        foreach ($grouped as $listeners) {
            $count = count($listeners);
            if ($count > $maxListeners) {
                $maxListeners = $count;
            }
        }

        $eventGraph = [];
        foreach ($grouped as $event => $listeners) {
            $listenerCount = count($listeners);
            $modules = array_unique(array_column($listeners, 'module'));
            sort($modules);
            $crossModuleCount = count($modules);
            $crossModuleRatio = $listenerCount > 0 ? $crossModuleCount / $listenerCount : 0;

            // Collect declaring modules
            $declaredBy = array_unique(array_column($listeners, 'declared_by'));
            sort($declaredBy);

            // Risk: high listener count (normalized) * cross-module diversity
            $normalizedCount = $listenerCount / $maxListeners;
            $riskScore = round($normalizedCount * (0.5 + 0.5 * $crossModuleRatio), 3);

            // Collect all evidence
            $allEvidence = [];
            foreach ($listeners as $l) {
                foreach ($l['evidence'] as $ev) {
                    $allEvidence[] = $ev;
                }
            }

            $eventGraph[] = [
                'event_id' => IdentityResolver::eventId($event),
                'event' => $event,
                'declared_by' => $declaredBy,
                'listener_count' => $listenerCount,
                'cross_module_listeners' => $crossModuleCount,
                'modules' => array_values($modules),
                'risk_score' => $riskScore,
                'listeners' => array_map(fn($o) => [
                    'observer_name' => $o['observer_name'],
                    'observer_class' => $o['observer_class'],
                    'method' => $o['method'],
                    'module' => $o['module'],
                    'scope' => $o['scope'],
                    'evidence' => $o['evidence'],
                ], $listeners),
                'evidence' => array_slice($allEvidence, 0, 10),
            ];
        }

        // Sort by risk score descending
        usort($eventGraph, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);

        return $eventGraph;
    }

    /**
     * Compute top impacted modules: modules that listen to the most events.
     *
     * @return array<array{module: string, event_count: int, events: string[]}>
     */
    private function computeTopImpactedModules(array $observers): array
    {
        $moduleEvents = [];
        foreach ($observers as $o) {
            if ($o['disabled']) {
                continue;
            }
            $mod = $o['module'];
            if (!isset($moduleEvents[$mod])) {
                $moduleEvents[$mod] = [];
            }
            $moduleEvents[$mod][$o['event_name']] = true;
        }

        $result = [];
        foreach ($moduleEvents as $mod => $events) {
            $eventList = array_keys($events);
            sort($eventList);
            $result[] = [
                'module' => $mod,
                'event_count' => count($eventList),
                'events' => $eventList,
            ];
        }

        usort($result, fn($a, $b) => $b['event_count'] <=> $a['event_count']);

        return array_slice($result, 0, 20);
    }

    private function detectScope(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $scopes = ['frontend', 'adminhtml', 'webapi_rest', 'webapi_soap', 'graphql', 'crontab'];

        foreach ($scopes as $scope) {
            if (str_contains($normalized, '/etc/' . $scope . '/events.xml')) {
                return $scope;
            }
        }

        return 'global';
    }

    /**
     * Return the top N most-observed events.
     *
     * @return array<array{event: string, listener_count: int, risk_score: float}>
     */
    private function topEvents(array $eventGraph, int $limit): array
    {
        $top = [];
        $i = 0;
        foreach ($eventGraph as $entry) {
            if ($i >= $limit) {
                break;
            }
            $top[] = [
                'event' => $entry['event'],
                'listener_count' => $entry['listener_count'],
                'risk_score' => $entry['risk_score'],
            ];
            $i++;
        }

        return $top;
    }
}

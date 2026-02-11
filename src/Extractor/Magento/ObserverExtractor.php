<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\ExtractorInterface;
use Symfony\Component\Finder\Finder;

class ObserverExtractor implements ExtractorInterface
{
    public function getName(): string
    {
        return 'events_observers';
    }

    public function getDescription(): string
    {
        return 'Extracts event/observer mappings from all events.xml files';
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
                $eventScope = $this->detectScope($file->getRelativePathname());
                $parsed = $this->parseEventsXml($file->getRealPath(), $repoPath, $eventScope);
                foreach ($parsed as $observer) {
                    $observers[] = $observer;
                }
            }
        }

        // Build event-centric view
        $eventMap = $this->buildEventMap($observers);

        return [
            'observers' => $observers,
            'event_map' => $eventMap,
            'summary' => [
                'total_observers' => count($observers),
                'total_events' => count($eventMap),
                'disabled_observers' => count(array_filter($observers, fn($o) => $o['disabled'])),
                'by_scope' => $this->countByScope($observers),
                'most_observed_events' => $this->topEvents($eventMap, 10),
            ],
        ];
    }

    private function parseEventsXml(string $filePath, string $repoPath, string $eventScope): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return [];
        }

        $relativePath = str_replace($repoPath . '/', '', $filePath);
        $observers = [];

        // <event name="event_name">
        //   <observer name="observer_name" instance="Observer\Class" />
        // </event>
        foreach ($xml->event ?? [] as $eventNode) {
            $eventName = (string) ($eventNode['name'] ?? '');
            if ($eventName === '') {
                continue;
            }

            foreach ($eventNode->observer ?? [] as $observerNode) {
                $observerName = (string) ($observerNode['name'] ?? '');
                $observerClass = (string) ($observerNode['instance'] ?? '');
                $disabled = strtolower((string) ($observerNode['disabled'] ?? 'false')) === 'true';

                if ($observerName === '') {
                    continue;
                }

                $observers[] = [
                    'event_name' => $eventName,
                    'observer_name' => $observerName,
                    'observer_class' => $observerClass,
                    'scope' => $eventScope,
                    'source_file' => $relativePath,
                    'disabled' => $disabled,
                ];
            }
        }

        return $observers;
    }

    /**
     * Group observers by event name for an event-centric view.
     *
     * @return array<string, array> event name => list of observers
     */
    private function buildEventMap(array $observers): array
    {
        $map = [];
        foreach ($observers as $observer) {
            if ($observer['disabled']) {
                continue;
            }
            $event = $observer['event_name'];
            $map[$event][] = [
                'observer_name' => $observer['observer_name'],
                'observer_class' => $observer['observer_class'],
                'scope' => $observer['scope'],
            ];
        }
        ksort($map);
        return $map;
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

    private function countByScope(array $observers): array
    {
        $counts = [];
        foreach ($observers as $o) {
            $scope = $o['scope'];
            $counts[$scope] = ($counts[$scope] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Return the top N most-observed events.
     *
     * @return array<array{event: string, observer_count: int}>
     */
    private function topEvents(array $eventMap, int $limit): array
    {
        $counts = [];
        foreach ($eventMap as $event => $observers) {
            $counts[$event] = count($observers);
        }
        arsort($counts);

        $top = [];
        $i = 0;
        foreach ($counts as $event => $count) {
            if ($i >= $limit) {
                break;
            }
            $top[] = ['event' => $event, 'observer_count' => $count];
            $i++;
        }

        return $top;
    }
}

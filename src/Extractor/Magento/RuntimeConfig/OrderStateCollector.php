<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Collects declared order status/state customizations and detects
 * state mutators (observers, plugins, setup scripts) that may modify
 * order state at runtime.
 *
 * Three output tiers:
 *  - Declared statuses/states (order_statuses.xml, config.xml): high confidence.
 *  - Status→state mappings (order_statuses.xml): high confidence.
 *  - State mutators (best_effort_detection): best-effort detection of explicit
 *    mutation seams — observers on state events, plugins on Order::setState/
 *    setStatus, setup scripts writing to sales_order_status tables.
 *    Does NOT detect indirect mutation via services, payment methods,
 *    repository saves, or async consumers.
 *
 * @see FW-M2-RT-002
 */
class OrderStateCollector
{
    private const STATE_EVENTS = [
        'sales_order_state_change_before',
        'sales_order_status_history_save_after',
        'sales_order_save_after',
        'sales_order_place_after',
    ];

    private const STATE_MUTATION_TARGETS = [
        'Magento\\Sales\\Model\\Order' => ['setState', 'setStatus', 'hold', 'unhold', 'cancel'],
        'Magento\\Sales\\Api\\OrderManagementInterface' => ['cancel', 'hold', 'unhold', 'place'],
        'Magento\\Sales\\Model\\Order\\Payment' => ['place', 'capture', 'refund', 'void'],
    ];

    public function collect(CollectorContext $ctx): array
    {
        $customStatuses = [];
        $customStates = [];
        $statusStateMappings = [];
        $stateMutators = [];

        foreach ($ctx->scopePaths() as $scopePath) {
            $this->collectFromConfigXml($ctx, $scopePath, $customStatuses);
            $this->collectFromOrderStatusesXml($ctx, $scopePath, $customStatuses, $statusStateMappings);
            $this->collectFromSetupScripts($ctx, $scopePath, $customStatuses, $customStates);
            $this->collectEventObserverMutators($ctx, $scopePath, $customStates);
            $this->collectPluginMutators($ctx, $scopePath, $stateMutators);
        }

        // Deduplicate statuses by status_code
        $dedupedStatuses = [];
        foreach ($customStatuses as $status) {
            $code = $status['status_code'];
            if (!isset($dedupedStatuses[$code])) {
                $dedupedStatuses[$code] = $status;
            } else {
                $dedupedStatuses[$code]['evidence'] = array_merge(
                    $dedupedStatuses[$code]['evidence'],
                    $status['evidence']
                );
                if ($status['label'] !== '' && $dedupedStatuses[$code]['label'] === '') {
                    $dedupedStatuses[$code]['label'] = $status['label'];
                }
            }
        }

        $customStatuses = array_values($dedupedStatuses);
        usort($customStatuses, fn($a, $b) => strcmp($a['status_code'], $b['status_code']));
        usort($statusStateMappings, fn($a, $b) => strcmp(
            $a['status_code'] . $a['state_code'],
            $b['status_code'] . $b['state_code']
        ));
        // Deduplicate plugins by plugin_name + target_class (same plugin in multiple area di.xml files)
        $dedupedMutators = [];
        foreach ($stateMutators as $mutator) {
            $key = $mutator['plugin_name'] . '|' . $mutator['target_class'];
            if (!isset($dedupedMutators[$key])) {
                $dedupedMutators[$key] = $mutator;
            } else {
                $dedupedMutators[$key]['evidence'] = array_merge(
                    $dedupedMutators[$key]['evidence'],
                    $mutator['evidence']
                );
            }
        }
        $stateMutators = array_values($dedupedMutators);

        usort($stateMutators, fn($a, $b) => strcmp(
            $a['target_class'] . $a['target_method'],
            $b['target_class'] . $b['target_method']
        ));

        return [
            'custom_statuses' => [
                '_meta' => [
                    'confidence' => 'authoritative_static',
                    'source_type' => 'repo_file',
                    'sources' => ['order_statuses.xml', 'config.xml', 'Setup/*.php'],
                    'runtime_required' => false,
                    'limitations' => 'Declared statuses from XML and setup scripts are authoritative for this repo snapshot.',
                ],
                'items' => $customStatuses,
            ],
            'status_state_mappings' => [
                '_meta' => [
                    'confidence' => 'authoritative_static',
                    'source_type' => 'repo_file',
                    'sources' => ['order_statuses.xml'],
                    'runtime_required' => false,
                    'limitations' => 'Declared status→state mappings are authoritative for this repo snapshot.',
                ],
                'items' => $statusStateMappings,
            ],
            'custom_states' => $customStates,
            'state_mutators' => [
                '_meta' => [
                    'confidence' => 'best_effort_detection',
                    'source_type' => 'repo_file',
                    'sources' => ['events.xml', 'di.xml'],
                    'runtime_required' => false,
                    'limitations' => 'Best-effort detection of explicit mutation seams only. '
                        . 'Does not detect indirect mutation via services, payment method internals, '
                        . 'repository saves, or async consumers.',
                ],
                'items' => $stateMutators,
            ],
        ];
    }

    private function collectFromConfigXml(CollectorContext $ctx, string $scopePath, array &$customStatuses): void
    {
        $ctx->findAndParseXml($scopePath, 'config.xml', function ($xml, $fileId, $module) use (&$customStatuses) {
            $defaultNode = $xml->default ?? null;
            if ($defaultNode === null) {
                return;
            }

            $salesNode = $defaultNode->sales ?? null;
            if ($salesNode === null) {
                return;
            }

            $orderStatusNode = $salesNode->order_status ?? null;
            if ($orderStatusNode !== null) {
                foreach ($orderStatusNode->children() as $statusCode => $statusNode) {
                    $label = (string) $statusNode;
                    if ($label === '' && $statusNode->count() > 0) {
                        $label = (string) ($statusNode->label ?? '');
                    }
                    $customStatuses[] = [
                        'status_code' => $statusCode,
                        'label' => $label,
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Custom order status '{$statusCode}'")->toArray()],
                    ];
                }
            }
        });
    }

    private function collectFromOrderStatusesXml(
        CollectorContext $ctx,
        string $scopePath,
        array &$customStatuses,
        array &$statusStateMappings
    ): void {
        $ctx->findAndParseXml($scopePath, 'order_statuses.xml', function ($xml, $fileId, $module) use (&$customStatuses, &$statusStateMappings) {
            foreach ($xml->status ?? [] as $statusNode) {
                $statusCode = (string) ($statusNode['status_code'] ?? '');
                $label = (string) ($statusNode['label'] ?? '');
                if ($statusCode === '') {
                    continue;
                }

                $customStatuses[] = [
                    'status_code' => $statusCode,
                    'label' => $label,
                    'module' => $module,
                    'evidence' => [Evidence::fromXml($fileId, "Registered order status '{$statusCode}'")->toArray()],
                ];

                foreach ($statusNode->state ?? [] as $stateNode) {
                    $stateCode = (string) ($stateNode['state_code'] ?? '');
                    $isDefault = strtolower((string) ($stateNode['is_default'] ?? 'false')) === 'true';
                    $visibleOnFront = strtolower((string) ($stateNode['visible_on_front'] ?? 'false')) === 'true';
                    if ($stateCode !== '') {
                        $statusStateMappings[] = [
                            'status_code' => $statusCode,
                            'state_code' => $stateCode,
                            'is_default' => $isDefault,
                            'visible_on_front' => $visibleOnFront,
                            'module' => $module,
                            'evidence' => [Evidence::fromXml($fileId, "Status '{$statusCode}' → state '{$stateCode}'")->toArray()],
                        ];
                    }
                }
            }
        });
    }

    private function collectFromSetupScripts(
        CollectorContext $ctx,
        string $scopePath,
        array &$customStatuses,
        array &$customStates
    ): void {
        $phpFinder = new Finder();
        $phpFinder->files()
            ->in($scopePath)
            ->name('*.php')
            ->path('/Setup/')
            ->sortByName();

        foreach ($phpFinder as $file) {
            $content = file_get_contents($file->getRealPath());
            $fileId = $ctx->fileId($file->getRealPath());
            $declaringModule = $ctx->resolveModuleFromFile($file->getRealPath());

            if (preg_match_all(
                '/(?:insertOnDuplicate|insert)\s*\(\s*[\'"]sales_order_status[\'"]|->setStatus\s*\(\s*[\'"]([a-z_]+)[\'"]\)/',
                $content,
                $matches
            )) {
                foreach (array_filter($matches[1] ?? []) as $statusCode) {
                    $customStatuses[] = [
                        'status_code' => $statusCode,
                        'label' => '',
                        'module' => $declaringModule,
                        'evidence' => [Evidence::fromPhpAst($fileId, 0, null, "Setup script registers status '{$statusCode}'")->toArray()],
                    ];
                }
            }

            if (preg_match_all(
                '/(?:insertOnDuplicate|insert)\s*\(\s*[\'"]sales_order_status_state[\'"]/',
                $content,
                $matches
            )) {
                $customStates[] = [
                    'source' => 'setup_script',
                    'module' => $declaringModule,
                    'evidence' => [Evidence::fromPhpAst($fileId, 0, null, 'Setup script modifies sales_order_status_state table')->toArray()],
                ];
            }
        }
    }

    private function collectEventObserverMutators(CollectorContext $ctx, string $scopePath, array &$customStates): void
    {
        $ctx->findAndParseXml($scopePath, 'events.xml', function ($xml, $fileId, $module) use (&$customStates) {
            foreach ($xml->event ?? [] as $eventNode) {
                $eventName = (string) ($eventNode['name'] ?? '');
                if (!in_array($eventName, self::STATE_EVENTS, true)) {
                    continue;
                }
                foreach ($eventNode->observer ?? [] as $obsNode) {
                    $obsName = (string) ($obsNode['name'] ?? '');
                    $obsInstance = IdentityResolver::normalizeFqcn((string) ($obsNode['instance'] ?? ''));
                    $customStates[] = [
                        'source' => 'event_observer',
                        'event' => $eventName,
                        'observer_name' => $obsName,
                        'observer_class' => $obsInstance,
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Observer '{$obsName}' on state event '{$eventName}'")->toArray()],
                    ];
                }
            }
        });
    }

    /**
     * Detect plugins on order state mutation methods (setState, setStatus, cancel, hold, etc.).
     */
    private function collectPluginMutators(CollectorContext $ctx, string $scopePath, array &$stateMutators): void
    {
        $ctx->findAndParseXml($scopePath, 'di.xml', function ($xml, $fileId, $module) use (&$stateMutators) {
            foreach ($xml->type ?? [] as $typeNode) {
                $typeName = IdentityResolver::normalizeFqcn((string) ($typeNode['name'] ?? ''));

                // Check if this type is a known state mutation target
                $targetMethods = null;
                foreach (self::STATE_MUTATION_TARGETS as $targetClass => $methods) {
                    $normalizedTarget = IdentityResolver::normalizeFqcn($targetClass);
                    if ($typeName === $normalizedTarget) {
                        $targetMethods = $methods;
                        break;
                    }
                }

                if ($targetMethods === null) {
                    continue;
                }

                foreach ($typeNode->plugin ?? [] as $pluginNode) {
                    $pluginName = (string) ($pluginNode['name'] ?? '');
                    $pluginType = IdentityResolver::normalizeFqcn((string) ($pluginNode['type'] ?? ''));
                    $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';

                    if ($pluginType === '' || $disabled) {
                        continue;
                    }

                    $stateMutators[] = [
                        'source' => 'plugin',
                        'confidence' => 'best_effort_detection',
                        'detection_method' => 'plugin',
                        'limitations' => 'Indirect mutation not detected',
                        'plugin_name' => $pluginName,
                        'plugin_class' => $pluginType,
                        'target_class' => $typeName,
                        'target_method' => implode(', ', $targetMethods),
                        'module' => $module,
                        'evidence' => [Evidence::fromXml($fileId, "Plugin '{$pluginName}' on {$typeName} (state mutation target)")->toArray()],
                    ];
                }
            }
        });
    }
}

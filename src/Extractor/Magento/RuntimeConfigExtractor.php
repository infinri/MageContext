<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Extractor\Magento\RuntimeConfig\CollectorContext;
use MageContext\Extractor\Magento\RuntimeConfig\DbConfigCollector;
use MageContext\Extractor\Magento\RuntimeConfig\MsiStatusCollector;
use MageContext\Extractor\Magento\RuntimeConfig\OrderStateCollector;
use MageContext\Extractor\Magento\RuntimeConfig\PaymentConfigCollector;
use MageContext\Extractor\Magento\RuntimeConfig\QueueTopologyCollector;
use MageContext\Extractor\Magento\RuntimeConfig\StockMappingCollector;

/**
 * Compositor extractor that delegates to six internal collectors.
 *
 * Each collector is single-purpose and produces its own _meta block
 * with confidence, source_type, sources, runtime_required, and limitations.
 *
 * Collectors:
 *  1. MsiStatusCollector — MSI module enabled/disabled (FW-M2-RT-001)
 *  2. StockMappingCollector — stock capability inference (FW-M2-RT-006)
 *  3. QueueTopologyCollector — declared queue topology & DLQ gaps (FW-M2-RT-005)
 *  4. DbConfigCollector — configured connection hints & declared engine intentions
 *  5. PaymentConfigCollector — declared payment method defaults & capabilities
 *  6. OrderStateCollector — declared statuses, state mappings, state mutators (FW-M2-RT-002)
 *
 * Output: single runtime_config.json with per-section _meta for source-of-truth tiering.
 */
class RuntimeConfigExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'runtime_config';
    }

    public function getDescription(): string
    {
        return 'Extracts runtime configuration signals: MSI status, stock capability, queue topology, DB hints, payment defaults, state machine evidence';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $ctx = new CollectorContext($repoPath, $scopes, $this->context, $this->getName());

        $msiModules        = (new MsiStatusCollector())->collect($ctx);
        $stockInfra        = (new StockMappingCollector())->collect($ctx);
        $queueConfig       = (new QueueTopologyCollector())->collect($ctx);
        $dbEngine          = (new DbConfigCollector())->collect($ctx);
        $paymentGateways   = (new PaymentConfigCollector())->collect($ctx);
        $stateMachine      = (new OrderStateCollector())->collect($ctx);

        return [
            'msi_modules' => $msiModules,
            'stock_infrastructure' => $stockInfra,
            'queue_config' => $queueConfig,
            'db_engine' => $dbEngine,
            'payment_gateways' => $paymentGateways,
            'state_machine_overrides' => $stateMachine,
            'summary' => [
                'msi_enabled_count' => count(array_filter($msiModules['modules'], fn($m) => $m['enabled'])),
                'msi_disabled_count' => count(array_filter($msiModules['modules'], fn($m) => !$m['enabled'])),
                'msi_active' => $msiModules['msi_active'],
                'stock_resolver_usages' => count($stockInfra['stock_resolver_preferences']),
                'legacy_stock_registry_usages' => count($stockInfra['legacy_stock_registry_preferences']),
                'total_queue_topics' => count($queueConfig['topics']),
                'total_queue_consumers' => count($queueConfig['consumers']),
                'queues_missing_dlq' => count($queueConfig['queues_missing_dlq']),
                'db_connections' => count($dbEngine['configured_connections']),
                'declared_engine_hints' => $dbEngine['declared_engine_hints'],
                'total_payment_methods' => count($paymentGateways['methods']),
                'active_payment_methods' => count(array_filter($paymentGateways['methods'], fn($m) => $m['active'])),
                'total_custom_statuses' => count($stateMachine['custom_statuses']['items']),
                'total_custom_states' => count($stateMachine['custom_states']),
                'status_state_mappings' => count($stateMachine['status_state_mappings']['items']),
                'state_mutators' => count($stateMachine['state_mutators']['items']),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Tests\Acceptance;

use MageContext\Config\CompilerConfig;
use MageContext\Extractor\CompilationContext;
use MageContext\Extractor\Magento\RuntimeConfigExtractor;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden fixture tests for RuntimeConfigExtractor.
 *
 * Tests against two realistic Magento repo snapshots:
 *  - msi_enabled_enterprise: MSI on, custom stock resolver, queue with DLQ,
 *    split DB connections, custom payment methods, custom order statuses + state mutators
 *  - msi_disabled_minimal: MSI off, single DB, no queues, no custom payment/order
 */
class RuntimeConfigFixtureTest extends TestCase
{
    private const FIXTURE_BASE = __DIR__ . '/../Fixtures/RuntimeConfig';

    private const VALID_CONFIDENCE_ENUMS = [
        'authoritative_static',
        'inferred_static',
        'declared_config',
        'best_effort_detection',
        'requires_runtime_introspection',
    ];

    // ──────────────────────────────────────────────────────────
    // Fixture 1: MSI-enabled enterprise
    // ──────────────────────────────────────────────────────────

    public function testMsiEnabledEnterprise_MsiActive(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');

        $this->assertTrue($result['msi_modules']['msi_active']);
        $this->assertTrue($result['msi_modules']['config_php_found']);
        $this->assertSame('authoritative_static', $result['msi_modules']['_meta']['confidence']);

        // 5 MSI modules total: Inventory, InventoryApi, InventorySales, InventorySourceSelection, InventoryCatalog
        $this->assertCount(5, $result['msi_modules']['modules']);
        $this->assertSame(4, $result['summary']['msi_enabled_count']);
        $this->assertSame(1, $result['summary']['msi_disabled_count']);

        // InventoryCatalog disabled
        $invCatalog = $this->findByKey($result['msi_modules']['modules'], 'module', 'Magento_InventoryCatalog');
        $this->assertNotNull($invCatalog);
        $this->assertFalse($invCatalog['enabled']);
    }

    public function testMsiEnabledEnterprise_StockInfrastructure(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');
        $stock = $result['stock_infrastructure'];

        $this->assertSame('inferred_static', $stock['_meta']['confidence']);
        $this->assertTrue($stock['_meta']['runtime_required']);

        $this->assertFalse($stock['actual_mapping_known']);

        // Custom stock resolver preference
        $this->assertSame(1, $result['summary']['stock_resolver_usages']);
        $this->assertSame(1, $result['summary']['legacy_stock_registry_usages']);

        // MSI table detected (inventory_stock_sales_channel)
        $tableNames = array_column($stock['msi_tables'], 'table');
        $this->assertContains('inventory_stock_sales_channel', $tableNames);
        // acme_warehouse_allocation does NOT match MSI pattern (no 'inventory_' prefix, no 'stock' in name)
        $this->assertNotContains('acme_warehouse_allocation', $tableNames);
    }

    public function testMsiEnabledEnterprise_QueueTopology(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');
        $queue = $result['queue_config'];

        $this->assertSame('authoritative_static', $queue['_meta']['confidence']);
        $this->assertCount(2, $queue['topics']);
        $this->assertCount(1, $queue['publishers']);
        $this->assertCount(2, $queue['consumers']);
        $this->assertCount(2, $queue['exchanges']);

        // Main queue has DLQ, stock update queue does NOT
        $this->assertCount(1, $queue['queues_missing_dlq']);
        $this->assertSame('acme.warehouse.stock.queue', $queue['queues_missing_dlq'][0]['queue']);

        // DLQ binding args
        $exchange = $this->findByKey($queue['exchanges'], 'exchange', 'acme.warehouse.exchange');
        $this->assertNotNull($exchange);
        $binding = $exchange['bindings'][0];
        $this->assertTrue($binding['has_dlq']);
        $this->assertSame(5, $binding['delivery_limit']);
        $this->assertSame(60000, $binding['message_ttl']);
    }

    public function testMsiEnabledEnterprise_DbConfig(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');
        $db = $result['db_engine'];

        $this->assertSame('declared_config', $db['_meta']['confidence']);
        $this->assertTrue($db['env_php_found']);
        $this->assertCount(3, $db['configured_connections']);
        $this->assertSame(3, $result['summary']['db_connections']);

        $default = $this->findByKey($db['configured_connections'], 'connection', 'default');
        $this->assertSame('READ COMMITTED', $default['configured_isolation_level']);

        $checkout = $this->findByKey($db['configured_connections'], 'connection', 'checkout');
        $this->assertSame('REPEATABLE READ', $checkout['configured_isolation_level']);

        $sales = $this->findByKey($db['configured_connections'], 'connection', 'sales');
        $this->assertNull($sales['configured_isolation_level']);

        // Declared table engines from db_schema.xml
        $this->assertGreaterThanOrEqual(3, count($db['declared_table_engines']));
        $this->assertArrayHasKey('innodb', $db['declared_engine_hints']);
        $this->assertArrayHasKey('memory', $db['declared_engine_hints']);
    }

    public function testMsiEnabledEnterprise_PaymentConfig(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');
        $payments = $result['payment_gateways'];

        $this->assertSame('declared_config', $payments['_meta']['confidence']);
        $this->assertCount(2, $payments['methods']);
        $this->assertSame(2, $result['summary']['active_payment_methods']);

        // Stripe: all 9 capabilities declared
        $stripe = $this->findByKey($payments['methods'], 'method_code', 'acme_stripe');
        $this->assertNotNull($stripe);
        $this->assertTrue($stripe['active']);
        $this->assertSame('authorize', $stripe['payment_action']);
        $this->assertTrue($stripe['is_gateway']);
        $this->assertTrue($stripe['capabilities_complete']);
        $this->assertTrue($stripe['declared_capabilities']['can_authorize']);
        $this->assertTrue($stripe['declared_capabilities']['can_capture_partial']);

        // COD: only 1 of 9 declared
        $cod = $this->findByKey($payments['methods'], 'method_code', 'acme_cod');
        $this->assertNotNull($cod);
        $this->assertFalse($cod['capabilities_complete']);
    }

    public function testMsiEnabledEnterprise_StateMachineAndMutators(): void
    {
        $result = $this->runFixture('msi_enabled_enterprise');
        $sm = $result['state_machine_overrides'];

        // Subsection-level _meta
        $this->assertSame('authoritative_static', $sm['custom_statuses']['_meta']['confidence']);
        $this->assertSame('authoritative_static', $sm['status_state_mappings']['_meta']['confidence']);
        $this->assertSame('best_effort_detection', $sm['state_mutators']['_meta']['confidence']);

        // 3 custom statuses
        $this->assertCount(3, $sm['custom_statuses']['items']);
        $this->assertSame(3, $result['summary']['total_custom_statuses']);

        // 3 status→state mappings
        $this->assertCount(3, $sm['status_state_mappings']['items']);

        // fraud_review is default for payment_review
        $fraudMapping = $this->findByKey($sm['status_state_mappings']['items'], 'status_code', 'fraud_review');
        $this->assertNotNull($fraudMapping);
        $this->assertSame('payment_review', $fraudMapping['state_code']);
        $this->assertTrue($fraudMapping['is_default']);

        // 2 event observers (state mutator detection)
        $this->assertCount(2, $sm['custom_states']);

        // 2 plugin mutators
        $this->assertCount(2, $sm['state_mutators']['items']);
        $this->assertSame(2, $result['summary']['state_mutators']);

        // Each mutator has best_effort_detection confidence + detection_method + limitations
        foreach ($sm['state_mutators']['items'] as $mutator) {
            $this->assertSame('best_effort_detection', $mutator['confidence']);
            $this->assertSame('plugin', $mutator['source']);
            $this->assertSame('plugin', $mutator['detection_method']);
            $this->assertSame('Indirect mutation not detected', $mutator['limitations']);
        }

        // Verify specific plugins
        $guard = $this->findByKey($sm['state_mutators']['items'], 'plugin_name', 'acme_order_state_guard');
        $this->assertNotNull($guard);
        $this->assertStringContainsString('OrderStateGuardPlugin', $guard['plugin_class']);
    }

    // ──────────────────────────────────────────────────────────
    // Fixture 2: MSI-disabled minimal
    // ──────────────────────────────────────────────────────────

    public function testMsiDisabledMinimal_MsiInactive(): void
    {
        $result = $this->runFixture('msi_disabled_minimal');

        $this->assertFalse($result['msi_modules']['msi_active']);
        $this->assertFalse($result['summary']['msi_active']);
        $this->assertSame(0, $result['summary']['msi_enabled_count']);
        $this->assertSame(3, $result['summary']['msi_disabled_count']);
    }

    public function testMsiDisabledMinimal_EmptyStockQueuesPaymentsStates(): void
    {
        $result = $this->runFixture('msi_disabled_minimal');

        // No stock infrastructure
        $this->assertEmpty($result['stock_infrastructure']['stock_resolver_preferences']);
        $this->assertEmpty($result['stock_infrastructure']['msi_tables']);

        // No queue topology
        $this->assertEmpty($result['queue_config']['topics']);
        $this->assertEmpty($result['queue_config']['consumers']);
        $this->assertSame(0, $result['summary']['total_queue_topics']);

        // No payment methods
        $this->assertEmpty($result['payment_gateways']['methods']);
        $this->assertSame(0, $result['summary']['total_payment_methods']);

        // No custom states
        $this->assertEmpty($result['state_machine_overrides']['custom_statuses']['items']);
        $this->assertEmpty($result['state_machine_overrides']['state_mutators']['items']);
        $this->assertSame(0, $result['summary']['state_mutators']);
    }

    public function testMsiDisabledMinimal_DbSingleConnection(): void
    {
        $result = $this->runFixture('msi_disabled_minimal');
        $db = $result['db_engine'];

        $this->assertTrue($db['env_php_found']);
        $this->assertCount(1, $db['configured_connections']);
        $this->assertSame(1, $result['summary']['db_connections']);

        $conn = $db['configured_connections'][0];
        $this->assertSame('default', $conn['connection']);
        $this->assertNull($conn['configured_isolation_level']);
    }

    // ──────────────────────────────────────────────────────────
    // Cross-fixture: confidence enum enforcement
    // ──────────────────────────────────────────────────────────

    #[DataProvider('fixtureProvider')]
    public function testAllSectionsUseStrictConfidenceEnum(string $fixtureName): void
    {
        $result = $this->runFixture($fixtureName);

        // Root-level _meta sections
        $rootSections = ['msi_modules', 'stock_infrastructure', 'queue_config',
                         'db_engine', 'payment_gateways'];
        foreach ($rootSections as $section) {
            $confidence = $result[$section]['_meta']['confidence'];
            $this->assertContains(
                $confidence,
                self::VALID_CONFIDENCE_ENUMS,
                "Section '{$section}' in fixture '{$fixtureName}' has invalid confidence: '{$confidence}'"
            );
        }

        // Subsection-level _meta for state_machine_overrides
        $sm = $result['state_machine_overrides'];
        foreach (['custom_statuses', 'status_state_mappings', 'state_mutators'] as $sub) {
            $confidence = $sm[$sub]['_meta']['confidence'];
            $this->assertContains(
                $confidence,
                self::VALID_CONFIDENCE_ENUMS,
                "state_machine_overrides.{$sub} in fixture '{$fixtureName}' has invalid confidence: '{$confidence}'"
            );
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testOutputIsDeterministicAcrossFixtures(string $fixtureName): void
    {
        $r1 = $this->runFixture($fixtureName);
        $r2 = $this->runFixture($fixtureName);

        $this->assertSame(
            json_encode($r1),
            json_encode($r2),
            "Fixture '{$fixtureName}' output must be deterministic"
        );
    }

    public static function fixtureProvider(): array
    {
        return [
            'msi_enabled_enterprise' => ['msi_enabled_enterprise'],
            'msi_disabled_minimal' => ['msi_disabled_minimal'],
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function runFixture(string $fixtureName): array
    {
        $repoPath = self::FIXTURE_BASE . '/' . $fixtureName;
        $scopes = ['app/code'];

        $moduleResolver = new ModuleResolver($repoPath);
        $moduleResolver->build($scopes);

        $config = CompilerConfig::load($repoPath);
        $warnings = new WarningCollector();

        $context = new CompilationContext(
            $repoPath,
            $scopes,
            $moduleResolver,
            $config,
            'fixture-commit',
            $warnings
        );

        $extractor = new RuntimeConfigExtractor();
        $extractor->setContext($context);
        return $extractor->extract($repoPath, $scopes);
    }

    private function findByKey(array $records, string $key, string $value): ?array
    {
        foreach ($records as $record) {
            if (($record[$key] ?? null) === $value) {
                return $record;
            }
        }
        return null;
    }
}

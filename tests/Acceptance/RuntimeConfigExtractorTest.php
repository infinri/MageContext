<?php

declare(strict_types=1);

namespace MageContext\Tests\Acceptance;

use MageContext\Config\CompilerConfig;
use MageContext\Extractor\CompilationContext;
use MageContext\Extractor\Magento\RuntimeConfigExtractor;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;
use MageContext\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests for RuntimeConfigExtractor (collector-based).
 *
 * Covers all six collectors plus confidence tiering and state mutators:
 *  1. MsiStatusCollector
 *  2. StockMappingCollector
 *  3. QueueTopologyCollector
 *  4. DbConfigCollector
 *  5. PaymentConfigCollector
 *  6. OrderStateCollector (with state_mutators)
 */
class RuntimeConfigExtractorTest extends TestCase
{
    use TempDirectoryTrait;

    protected function setUp(): void
    {
        $this->createTmpDir('runtime-config');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────
    // 1. MSI module status
    // ──────────────────────────────────────────────────────────

    public function testMsiModulesExtractsEnabledAndDisabledStatus(): void
    {
        $this->writeFixture('app/etc/config.php', <<<'PHP'
<?php
return [
    'modules' => [
        'Magento_Store' => 1,
        'Magento_Inventory' => 1,
        'Magento_InventoryApi' => 1,
        'Magento_InventorySourceSelection' => 0,
        'Magento_InventorySales' => 1,
        'Vendor_Custom' => 1,
    ],
];
PHP
        );

        $this->writeFixture('app/code/Vendor/Custom/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Custom"/></config>
XML
        );

        $result = $this->runExtractor();

        $this->assertArrayHasKey('msi_modules', $result);
        $msi = $result['msi_modules'];

        // _meta tiering — strict enum
        $this->assertSame('authoritative_static', $msi['_meta']['confidence']);
        $this->assertFalse($msi['_meta']['runtime_required']);

        $this->assertTrue($msi['config_php_found']);
        $this->assertTrue($msi['msi_active']);
        $this->assertCount(4, $msi['modules']);

        $inventory = $this->findByKey($msi['modules'], 'module', 'Magento_Inventory');
        $this->assertNotNull($inventory);
        $this->assertTrue($inventory['enabled']);
        $this->assertNotEmpty($inventory['evidence']);

        $sourceSelection = $this->findByKey($msi['modules'], 'module', 'Magento_InventorySourceSelection');
        $this->assertNotNull($sourceSelection);
        $this->assertFalse($sourceSelection['enabled']);

        // Vendor_Custom should NOT appear (not an MSI module)
        $this->assertNull($this->findByKey($msi['modules'], 'module', 'Vendor_Custom'));

        // Summary
        $this->assertSame(3, $result['summary']['msi_enabled_count']);
        $this->assertSame(1, $result['summary']['msi_disabled_count']);
        $this->assertTrue($result['summary']['msi_active']);
    }

    public function testMsiModulesReportsInactiveWhenCoreDisabled(): void
    {
        $this->writeFixture('app/etc/config.php', <<<'PHP'
<?php
return [
    'modules' => [
        'Magento_Inventory' => 0,
        'Magento_InventoryApi' => 0,
    ],
];
PHP
        );

        $this->writeFixture('app/code/Vendor/Stub/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stub"/></config>
XML
        );

        $result = $this->runExtractor();

        $this->assertFalse($result['msi_modules']['msi_active']);
        $this->assertFalse($result['summary']['msi_active']);
    }

    public function testMsiModulesHandlesMissingConfigPhp(): void
    {
        $this->writeFixture('app/code/Vendor/Stub/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stub"/></config>
XML
        );

        $result = $this->runExtractor();

        $this->assertFalse($result['msi_modules']['config_php_found']);
        $this->assertFalse($result['msi_modules']['msi_active']);
        $this->assertEmpty($result['msi_modules']['modules']);
    }

    // ──────────────────────────────────────────────────────────
    // 2. Stock infrastructure (capability inference)
    // ──────────────────────────────────────────────────────────

    public function testStockInfrastructureDetectsResolverPreferences(): void
    {
        $this->writeFixture('app/code/Vendor/Stock/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Magento\InventorySalesApi\Api\StockResolverInterface" type="Vendor\Stock\Model\CustomStockResolver"/>
    <preference for="Magento\CatalogInventory\Api\StockRegistryInterface" type="Vendor\Stock\Model\LegacyStockRegistry"/>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Stock/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stock"/></config>
XML
        );

        $result = $this->runExtractor();
        $stock = $result['stock_infrastructure'];

        // _meta: inferred_static — capability inference, not live mappings
        $this->assertSame('inferred_static', $stock['_meta']['confidence']);
        $this->assertTrue($stock['_meta']['runtime_required']);
        $this->assertFalse($stock['actual_mapping_known']);

        $this->assertCount(1, $stock['stock_resolver_preferences']);
        $this->assertStringContainsString('StockResolverInterface', $stock['stock_resolver_preferences'][0]['interface']);
        $this->assertStringContainsString('CustomStockResolver', $stock['stock_resolver_preferences'][0]['implementation']);

        $this->assertCount(1, $stock['legacy_stock_registry_preferences']);
        $this->assertStringContainsString('StockRegistryInterface', $stock['legacy_stock_registry_preferences'][0]['interface']);

        $this->assertSame(1, $result['summary']['stock_resolver_usages']);
        $this->assertSame(1, $result['summary']['legacy_stock_registry_usages']);
    }

    public function testStockInfrastructureDetectsMsiTables(): void
    {
        $this->writeFixture('app/code/Vendor/Stock/etc/db_schema.xml', <<<'XML'
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">
    <table name="inventory_stock_sales_channel" resource="default" engine="innodb">
        <column xsi:type="int" name="stock_id" unsigned="true" nullable="false"/>
        <column xsi:type="varchar" name="type" nullable="false" length="64"/>
        <column xsi:type="varchar" name="code" nullable="false" length="64"/>
    </table>
    <table name="inventory_source_item" resource="default" engine="innodb">
        <column xsi:type="int" name="source_item_id" unsigned="true" identity="true" nullable="false"/>
    </table>
    <table name="regular_table" resource="default" engine="innodb">
        <column xsi:type="int" name="id" unsigned="true" identity="true" nullable="false"/>
    </table>
</schema>
XML
        );

        $this->writeFixture('app/code/Vendor/Stock/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stock"/></config>
XML
        );

        $result = $this->runExtractor();
        $stock = $result['stock_infrastructure'];

        $this->assertCount(2, $stock['msi_tables']);
        $tableNames = array_column($stock['msi_tables'], 'table');
        $this->assertContains('inventory_source_item', $tableNames);
        $this->assertContains('inventory_stock_sales_channel', $tableNames);
        $this->assertNotContains('regular_table', $tableNames);
    }

    // ──────────────────────────────────────────────────────────
    // 3. Queue topology (declared topology, not broker policy)
    // ──────────────────────────────────────────────────────────

    public function testQueueConfigExtractsFullPipeline(): void
    {
        $this->writeFixture('app/code/Vendor/Queue/etc/communication.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Communication/etc/communication.xsd">
    <topic name="vendor.queue.process" request="Vendor\Queue\Api\Data\MessageInterface"/>
    <topic name="vendor.queue.notify" request="string"/>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Queue/etc/queue_publisher.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/publisher.xsd">
    <publisher topic="vendor.queue.process">
        <connection name="amqp" exchange="vendor.queue.exchange"/>
    </publisher>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Queue/etc/queue_topology.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/topology.xsd">
    <exchange name="vendor.queue.dlx" type="topic" connection="amqp">
        <binding id="vendor.queue.dlq.binding" topic="vendor.queue.process" destinationType="queue" destination="vendor.queue.dlq"/>
    </exchange>
    <exchange name="vendor.queue.exchange" type="topic" connection="amqp">
        <binding id="vendor.queue.binding" topic="vendor.queue.process" destinationType="queue" destination="vendor.queue.main">
            <arguments>
                <argument name="x-dead-letter-exchange" xsi:type="string">vendor.queue.dlx</argument>
                <argument name="x-delivery-limit" xsi:type="number">3</argument>
                <argument name="x-message-ttl" xsi:type="number">30000</argument>
            </arguments>
        </binding>
    </exchange>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Queue/etc/queue_consumer.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework-message-queue:etc/consumer.xsd">
    <consumer name="vendor.queue.consumer" queue="vendor.queue.main" handler="Vendor\Queue\Model\Consumer::process" connection="amqp" maxMessages="1000"/>
    <consumer name="vendor.notify.consumer" queue="vendor.notify.queue" handler="Vendor\Queue\Model\NotifyConsumer::process" connection="amqp" maxMessages="500"/>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Queue/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Queue"/></config>
XML
        );

        $result = $this->runExtractor();
        $queue = $result['queue_config'];

        // _meta: authoritative_static for declared topology
        $this->assertSame('authoritative_static', $queue['_meta']['confidence']);
        $this->assertTrue($queue['_meta']['runtime_required']);

        // Topics
        $this->assertCount(2, $queue['topics']);
        $processTopic = $this->findByKey($queue['topics'], 'topic', 'vendor.queue.process');
        $this->assertNotNull($processTopic);
        $this->assertSame('Vendor\Queue\Api\Data\MessageInterface', $processTopic['request']);

        // Publishers
        $this->assertCount(1, $queue['publishers']);
        $this->assertSame('vendor.queue.process', $queue['publishers'][0]['topic']);

        // Consumers
        $this->assertCount(2, $queue['consumers']);
        $mainConsumer = $this->findByKey($queue['consumers'], 'consumer', 'vendor.queue.consumer');
        $this->assertNotNull($mainConsumer);
        $this->assertSame('vendor.queue.main', $mainConsumer['queue']);
        $this->assertSame(1000, $mainConsumer['max_messages']);

        // Exchanges
        $this->assertCount(2, $queue['exchanges']);

        // DLQ detection
        $mainExchange = $this->findByKey($queue['exchanges'], 'exchange', 'vendor.queue.exchange');
        $this->assertNotNull($mainExchange);
        $binding = $mainExchange['bindings'][0];
        $this->assertTrue($binding['has_dlq']);
        $this->assertSame('vendor.queue.dlx', $binding['dlq_exchange']);
        $this->assertSame(3, $binding['delivery_limit']);
        $this->assertSame(30000, $binding['message_ttl']);

        // Queues missing DLQ
        $this->assertCount(1, $queue['queues_missing_dlq']);
        $this->assertSame('vendor.notify.queue', $queue['queues_missing_dlq'][0]['queue']);

        // Summary
        $this->assertSame(2, $result['summary']['total_queue_topics']);
        $this->assertSame(2, $result['summary']['total_queue_consumers']);
        $this->assertSame(1, $result['summary']['queues_missing_dlq']);
    }

    // ──────────────────────────────────────────────────────────
    // 4. DB config (configured hints, not live state)
    // ──────────────────────────────────────────────────────────

    public function testDbConfigExtractsConnectionHintsAndDeclaredEngines(): void
    {
        $this->writeFixture('app/etc/env.php', <<<'PHP'
<?php
return [
    'db' => [
        'connection' => [
            'default' => [
                'host' => 'localhost',
                'dbname' => 'magento',
                'engine' => 'innodb',
                'active' => '1',
                'isolation_level' => 'READ COMMITTED',
            ],
            'sales' => [
                'host' => 'localhost',
                'dbname' => 'magento_sales',
                'engine' => 'innodb',
                'active' => '1',
            ],
        ],
    ],
];
PHP
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/db_schema.xml', <<<'XML'
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">
    <table name="catalog_product_entity" resource="default" engine="innodb">
        <column xsi:type="int" name="entity_id" unsigned="true" identity="true" nullable="false"/>
    </table>
    <table name="catalog_product_flat" resource="default" engine="memory">
        <column xsi:type="int" name="entity_id" unsigned="true" nullable="false"/>
    </table>
</schema>
XML
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Catalog"/></config>
XML
        );

        $result = $this->runExtractor();
        $db = $result['db_engine'];

        // _meta: declared_config — env.php hints + db_schema.xml intentions
        $this->assertSame('declared_config', $db['_meta']['confidence']);
        $this->assertTrue($db['_meta']['runtime_required']);

        $this->assertTrue($db['env_php_found']);

        // Configured connections (renamed from 'connections')
        $this->assertCount(2, $db['configured_connections']);
        $defaultConn = $this->findByKey($db['configured_connections'], 'connection', 'default');
        $this->assertNotNull($defaultConn);
        $this->assertSame('innodb', $defaultConn['configured_engine']);
        $this->assertSame('READ COMMITTED', $defaultConn['configured_isolation_level']);
        $this->assertTrue($defaultConn['active']);

        $salesConn = $this->findByKey($db['configured_connections'], 'connection', 'sales');
        $this->assertNotNull($salesConn);
        $this->assertNull($salesConn['configured_isolation_level']);

        // Declared table engines (renamed from 'table_engines')
        $this->assertCount(2, $db['declared_table_engines']);
        $flatTable = $this->findByKey($db['declared_table_engines'], 'table', 'catalog_product_flat');
        $this->assertNotNull($flatTable);
        $this->assertSame('memory', $flatTable['declared_engine']);

        // Declared engine hints (renamed from 'engine_distribution')
        $this->assertSame(['innodb' => 1, 'memory' => 1], $db['declared_engine_hints']);
        $this->assertSame(2, $result['summary']['db_connections']);
    }

    public function testDbConfigHandlesMissingEnvPhp(): void
    {
        $this->writeFixture('app/code/Vendor/Stub/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stub"/></config>
XML
        );

        $result = $this->runExtractor();

        $this->assertFalse($result['db_engine']['env_php_found']);
        $this->assertEmpty($result['db_engine']['configured_connections']);
    }

    // ──────────────────────────────────────────────────────────
    // 5. Payment config (declared defaults, not authoritative)
    // ──────────────────────────────────────────────────────────

    public function testPaymentConfigExtractsDeclaredDefaults(): void
    {
        $this->writeFixture('app/code/Vendor/Payment/etc/config.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Store:etc/config.xsd">
    <default>
        <payment>
            <vendor_gateway>
                <model>Vendor\Payment\Model\GatewayAdapter</model>
                <active>1</active>
                <title>Vendor Gateway</title>
                <payment_action>authorize_capture</payment_action>
                <order_status>processing</order_status>
                <is_gateway>1</is_gateway>
                <can_authorize>1</can_authorize>
                <can_capture>1</can_capture>
                <can_refund>1</can_refund>
                <can_void>1</can_void>
                <can_use_checkout>1</can_use_checkout>
                <can_capture_partial>0</can_capture_partial>
            </vendor_gateway>
            <vendor_offline>
                <model>Vendor\Payment\Model\OfflineMethod</model>
                <active>0</active>
                <title>Vendor Offline</title>
                <payment_action>order</payment_action>
                <order_status>pending</order_status>
                <can_authorize>0</can_authorize>
            </vendor_offline>
        </payment>
    </default>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Payment/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Payment"/></config>
XML
        );

        $result = $this->runExtractor();
        $payments = $result['payment_gateways'];

        // _meta: declared_config — config.xml defaults only
        $this->assertSame('declared_config', $payments['_meta']['confidence']);
        $this->assertTrue($payments['_meta']['runtime_required']);

        $this->assertCount(2, $payments['methods']);

        // Vendor gateway — declared_capabilities (renamed from 'capabilities')
        $gateway = $this->findByKey($payments['methods'], 'method_code', 'vendor_gateway');
        $this->assertNotNull($gateway);
        $this->assertStringContainsString('GatewayAdapter', $gateway['model']);
        $this->assertTrue($gateway['active']);
        $this->assertSame('Vendor Gateway', $gateway['title']);
        $this->assertSame('authorize_capture', $gateway['payment_action']);
        $this->assertSame('processing', $gateway['order_status']);
        $this->assertTrue($gateway['is_gateway']);
        $this->assertTrue($gateway['declared_capabilities']['can_authorize']);
        $this->assertTrue($gateway['declared_capabilities']['can_capture']);
        $this->assertTrue($gateway['declared_capabilities']['can_refund']);
        $this->assertFalse($gateway['declared_capabilities']['can_capture_partial']);
        $this->assertFalse($gateway['capabilities_complete']); // not all 9 declared
        $this->assertNotEmpty($gateway['evidence']);

        // Offline method
        $offline = $this->findByKey($payments['methods'], 'method_code', 'vendor_offline');
        $this->assertNotNull($offline);
        $this->assertFalse($offline['active']);
        $this->assertSame('order', $offline['payment_action']);
        $this->assertFalse($offline['capabilities_complete']); // only 1 of 9 declared

        // Summary
        $this->assertSame(2, $result['summary']['total_payment_methods']);
        $this->assertSame(1, $result['summary']['active_payment_methods']);
    }

    // ──────────────────────────────────────────────────────────
    // 6. Order state machine (declared + state mutators)
    // ──────────────────────────────────────────────────────────

    public function testStateMachineExtractsCustomStatuses(): void
    {
        $this->writeFixture('app/code/Vendor/Sales/etc/order_statuses.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Sales:etc/order_statuses.xsd">
    <status status_code="custom_review" label="Custom Review">
        <state state_code="new" is_default="false" visible_on_front="true"/>
    </status>
    <status status_code="fraud_suspected" label="Fraud Suspected">
        <state state_code="payment_review" is_default="true" visible_on_front="false"/>
    </status>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Sales/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Sales"/></config>
XML
        );

        $result = $this->runExtractor();
        $sm = $result['state_machine_overrides'];

        // Subsection-level _meta: statuses are authoritative_static
        $this->assertSame('authoritative_static', $sm['custom_statuses']['_meta']['confidence']);
        $this->assertSame('authoritative_static', $sm['status_state_mappings']['_meta']['confidence']);
        $this->assertSame('best_effort_detection', $sm['state_mutators']['_meta']['confidence']);

        // Custom statuses (now under ['items'])
        $this->assertCount(2, $sm['custom_statuses']['items']);
        $review = $this->findByKey($sm['custom_statuses']['items'], 'status_code', 'custom_review');
        $this->assertNotNull($review);
        $this->assertSame('Custom Review', $review['label']);

        $fraud = $this->findByKey($sm['custom_statuses']['items'], 'status_code', 'fraud_suspected');
        $this->assertNotNull($fraud);
        $this->assertSame('Fraud Suspected', $fraud['label']);

        // Status → state mappings (now under ['items'])
        $this->assertCount(2, $sm['status_state_mappings']['items']);
        $reviewMapping = $this->findByKey($sm['status_state_mappings']['items'], 'status_code', 'custom_review');
        $this->assertNotNull($reviewMapping);
        $this->assertSame('new', $reviewMapping['state_code']);
        $this->assertFalse($reviewMapping['is_default']);
        $this->assertTrue($reviewMapping['visible_on_front']);

        $fraudMapping = $this->findByKey($sm['status_state_mappings']['items'], 'status_code', 'fraud_suspected');
        $this->assertNotNull($fraudMapping);
        $this->assertSame('payment_review', $fraudMapping['state_code']);
        $this->assertTrue($fraudMapping['is_default']);

        // Summary
        $this->assertSame(2, $result['summary']['total_custom_statuses']);
        $this->assertSame(2, $result['summary']['status_state_mappings']);
    }

    public function testStateMachineDetectsOrderStateEventObservers(): void
    {
        $this->writeFixture('app/code/Vendor/Sales/etc/events.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="sales_order_state_change_before">
        <observer name="vendor_state_change" instance="Vendor\Sales\Observer\OrderStateChangeObserver"/>
    </event>
    <event name="sales_order_place_after">
        <observer name="vendor_order_placed" instance="Vendor\Sales\Observer\OrderPlacedObserver"/>
    </event>
    <event name="catalog_product_save_after">
        <observer name="vendor_product_save" instance="Vendor\Sales\Observer\ProductObserver"/>
    </event>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Sales/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Sales"/></config>
XML
        );

        $result = $this->runExtractor();
        $sm = $result['state_machine_overrides'];

        // Should detect observers on state-related events only
        $this->assertCount(2, $sm['custom_states']);

        $stateChangeObs = null;
        $placeObs = null;
        foreach ($sm['custom_states'] as $state) {
            if (($state['event'] ?? '') === 'sales_order_state_change_before') {
                $stateChangeObs = $state;
            }
            if (($state['event'] ?? '') === 'sales_order_place_after') {
                $placeObs = $state;
            }
        }

        $this->assertNotNull($stateChangeObs);
        $this->assertSame('event_observer', $stateChangeObs['source']);
        $this->assertStringContainsString('OrderStateChangeObserver', $stateChangeObs['observer_class']);

        $this->assertNotNull($placeObs);
        $this->assertSame('event_observer', $placeObs['source']);

        // catalog_product_save_after is NOT a state event
        $this->assertSame(2, $result['summary']['total_custom_states']);
    }

    public function testStateMachineDetectsPluginMutators(): void
    {
        $this->writeFixture('app/code/Vendor/Sales/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Sales\Model\Order">
        <plugin name="vendor_order_state_plugin" type="Vendor\Sales\Plugin\OrderStatePlugin"/>
    </type>
    <type name="Magento\Sales\Api\OrderManagementInterface">
        <plugin name="vendor_order_mgmt_plugin" type="Vendor\Sales\Plugin\OrderMgmtPlugin"/>
    </type>
    <type name="Magento\Catalog\Model\Product">
        <plugin name="vendor_product_plugin" type="Vendor\Sales\Plugin\ProductPlugin"/>
    </type>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Sales/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Sales"/></config>
XML
        );

        $result = $this->runExtractor();
        $sm = $result['state_machine_overrides'];

        // Subsection _meta for state_mutators
        $this->assertSame('best_effort_detection', $sm['state_mutators']['_meta']['confidence']);

        // Should detect plugins on Order and OrderManagementInterface, NOT on Product
        $this->assertCount(2, $sm['state_mutators']['items']);

        $orderPlugin = null;
        $mgmtPlugin = null;
        foreach ($sm['state_mutators']['items'] as $mutator) {
            if ($mutator['plugin_name'] === 'vendor_order_state_plugin') {
                $orderPlugin = $mutator;
            }
            if ($mutator['plugin_name'] === 'vendor_order_mgmt_plugin') {
                $mgmtPlugin = $mutator;
            }
        }

        $this->assertNotNull($orderPlugin);
        $this->assertSame('plugin', $orderPlugin['source']);
        $this->assertSame('best_effort_detection', $orderPlugin['confidence']);
        $this->assertSame('plugin', $orderPlugin['detection_method']);
        $this->assertSame('Indirect mutation not detected', $orderPlugin['limitations']);
        $this->assertStringContainsString('OrderStatePlugin', $orderPlugin['plugin_class']);
        $this->assertStringContainsString('Order', $orderPlugin['target_class']);
        $this->assertNotEmpty($orderPlugin['evidence']);

        $this->assertNotNull($mgmtPlugin);
        $this->assertStringContainsString('OrderManagementInterface', $mgmtPlugin['target_class']);
        $this->assertSame('plugin', $mgmtPlugin['detection_method']);

        // Summary includes state_mutators count
        $this->assertSame(2, $result['summary']['state_mutators']);
    }

    // ──────────────────────────────────────────────────────────
    // Full integration + confidence tiering
    // ──────────────────────────────────────────────────────────

    public function testFullExtractionOutputStructureAndMeta(): void
    {
        $this->writeFixture('app/etc/config.php', <<<'PHP'
<?php
return [
    'modules' => [
        'Magento_Inventory' => 1,
        'Vendor_Custom' => 1,
    ],
];
PHP
        );

        $this->writeFixture('app/etc/env.php', <<<'PHP'
<?php
return [
    'db' => [
        'connection' => [
            'default' => [
                'host' => 'localhost',
                'dbname' => 'magento',
                'engine' => 'innodb',
                'active' => '1',
            ],
        ],
    ],
];
PHP
        );

        $this->writeFixture('app/code/Vendor/Custom/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Custom"/></config>
XML
        );

        $result = $this->runExtractor();

        // Verify all top-level keys exist
        $this->assertArrayHasKey('msi_modules', $result);
        $this->assertArrayHasKey('stock_infrastructure', $result);
        $this->assertArrayHasKey('queue_config', $result);
        $this->assertArrayHasKey('db_engine', $result);
        $this->assertArrayHasKey('payment_gateways', $result);
        $this->assertArrayHasKey('state_machine_overrides', $result);
        $this->assertArrayHasKey('summary', $result);

        // Sections with root-level _meta
        $rootMetaSections = ['msi_modules', 'stock_infrastructure', 'queue_config',
                            'db_engine', 'payment_gateways'];
        foreach ($rootMetaSections as $section) {
            $meta = $result[$section]['_meta'];
            $this->assertArrayHasKey('confidence', $meta, "Missing _meta.confidence in {$section}");
            $this->assertArrayHasKey('source_type', $meta, "Missing _meta.source_type in {$section}");
            $this->assertArrayHasKey('sources', $meta, "Missing _meta.sources in {$section}");
            $this->assertArrayHasKey('runtime_required', $meta, "Missing _meta.runtime_required in {$section}");
            $this->assertArrayHasKey('limitations', $meta, "Missing _meta.limitations in {$section}");
            $this->assertContains($meta['confidence'], [
                'authoritative_static',
                'inferred_static',
                'declared_config',
                'best_effort_detection',
                'requires_runtime_introspection',
            ], "Invalid confidence enum in {$section}: {$meta['confidence']}");
        }

        // state_machine_overrides uses subsection-level _meta
        $sm = $result['state_machine_overrides'];
        $subSections = ['custom_statuses', 'status_state_mappings', 'state_mutators'];
        foreach ($subSections as $sub) {
            $meta = $sm[$sub]['_meta'];
            $this->assertArrayHasKey('confidence', $meta, "Missing _meta.confidence in state_machine_overrides.{$sub}");
            $this->assertContains($meta['confidence'], [
                'authoritative_static',
                'inferred_static',
                'declared_config',
                'best_effort_detection',
                'requires_runtime_introspection',
            ], "Invalid confidence enum in state_machine_overrides.{$sub}: {$meta['confidence']}");
        }

        // Verify summary has all expected keys
        $summaryKeys = [
            'msi_enabled_count', 'msi_disabled_count', 'msi_active',
            'stock_resolver_usages', 'legacy_stock_registry_usages',
            'total_queue_topics', 'total_queue_consumers', 'queues_missing_dlq',
            'db_connections', 'declared_engine_hints',
            'total_payment_methods', 'active_payment_methods',
            'total_custom_statuses', 'total_custom_states', 'status_state_mappings',
            'state_mutators',
        ];
        foreach ($summaryKeys as $key) {
            $this->assertArrayHasKey($key, $result['summary'], "Missing summary key: {$key}");
        }
    }

    // ──────────────────────────────────────────────────────────
    // Determinism
    // ──────────────────────────────────────────────────────────

    public function testOutputIsDeterministic(): void
    {
        $this->writeFixture('app/etc/config.php', <<<'PHP'
<?php
return [
    'modules' => [
        'Magento_InventorySales' => 1,
        'Magento_Inventory' => 1,
    ],
];
PHP
        );

        $this->writeFixture('app/code/Vendor/Stub/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Stub"/></config>
XML
        );

        $r1 = $this->runExtractor();
        $r2 = $this->runExtractor();

        $this->assertSame(
            json_encode($r1),
            json_encode($r2),
            'RuntimeConfigExtractor output must be deterministic across runs'
        );

        // Verify sorted order: Magento_Inventory before Magento_InventorySales
        $modules = $r1['msi_modules']['modules'];
        $this->assertSame('Magento_Inventory', $modules[0]['module']);
        $this->assertSame('Magento_InventorySales', $modules[1]['module']);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function writeFixture(string $relativePath, string $content): void
    {
        $fullPath = $this->tmpDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, $content);
    }

    private function runExtractor(): array
    {
        $scopes = ['app/code'];

        $moduleResolver = new ModuleResolver($this->tmpDir);
        $moduleResolver->build($scopes);

        $config = CompilerConfig::load($this->tmpDir);
        $warnings = new WarningCollector();

        $context = new CompilationContext(
            $this->tmpDir,
            $scopes,
            $moduleResolver,
            $config,
            'test-commit',
            $warnings
        );

        $extractor = new RuntimeConfigExtractor();
        $extractor->setContext($context);
        return $extractor->extract($this->tmpDir, $scopes);
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

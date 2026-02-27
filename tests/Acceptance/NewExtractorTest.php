<?php

declare(strict_types=1);

namespace MageContext\Tests\Acceptance;

use MageContext\Config\CompilerConfig;
use MageContext\Extractor\CompilationContext;
use MageContext\Extractor\Magento\CallGraphExtractor;
use MageContext\Extractor\Magento\DtoDataInterfaceExtractor;
use MageContext\Extractor\Magento\EntityRelationshipExtractor;
use MageContext\Extractor\Magento\ImplementationPatternExtractor;
use MageContext\Extractor\Magento\PluginSeamTimingExtractor;
use MageContext\Extractor\Magento\RepositoryPatternExtractor;
use MageContext\Extractor\Magento\SafeApiMatrixExtractor;
use MageContext\Extractor\Magento\ServiceContractExtractor;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;
use MageContext\Tests\Support\TempDirectoryTrait;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests for all 8 new extractors.
 *
 * Each test builds a realistic Magento-like fixture on disk, wires up
 * the CompilationContext, runs the extractor, and asserts the output
 * structure and content are correct.
 */
class NewExtractorTest extends TestCase
{
    use TempDirectoryTrait;

    protected function setUp(): void
    {
        $this->createTmpDir('new-extractors');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 2: ServiceContractExtractor
    // ──────────────────────────────────────────────────────────

    public function testServiceContractExtractsInterfaceMethodSignatures(): void
    {
        $this->writeFixture('app/code/Vendor/Coupon/Api/CouponManagementInterface.php', <<<'PHP'
<?php
namespace Vendor\Coupon\Api;

/**
 * Coupon management service.
 */
interface CouponManagementInterface
{
    /**
     * Apply a coupon code to a cart.
     *
     * @param int $cartId
     * @param string $couponCode
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function set(int $cartId, string $couponCode): bool;

    /**
     * Remove coupon from cart.
     *
     * @param int $cartId
     * @return bool
     */
    public function remove(int $cartId): bool;
}
PHP
        );

        // di.xml with a preference binding
        $this->writeFixture('app/code/Vendor/Coupon/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Vendor\Coupon\Api\CouponManagementInterface" type="Vendor\Coupon\Model\CouponManagement"/>
</config>
XML
        );

        // webapi.xml exposing the interface
        $this->writeFixture('app/code/Vendor/Coupon/etc/webapi.xml', <<<'XML'
<?xml version="1.0"?>
<routes xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Webapi:etc/webapi.xsd">
    <route url="/V1/carts/:cartId/coupons/:couponCode" method="PUT">
        <service class="Vendor\Coupon\Api\CouponManagementInterface" method="set"/>
        <resources>
            <resource ref="self"/>
        </resources>
    </route>
</routes>
XML
        );

        // module.xml for module resolution
        $this->writeFixture('app/code/Vendor/Coupon/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Coupon"/></config>
XML
        );

        $extractor = new ServiceContractExtractor();
        $result = $this->runExtractor($extractor);

        // Structure assertions
        $this->assertArrayHasKey('contracts', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(1, $result['contracts']);

        $contract = $result['contracts'][0];
        $this->assertSame('Vendor\Coupon\Api\CouponManagementInterface', $contract['interface']);
        $this->assertSame('Vendor_Coupon', $contract['module']);
        $this->assertCount(2, $contract['methods']);

        // Method signatures
        $methodNames = array_column($contract['methods'], 'name');
        $this->assertContains('set', $methodNames);
        $this->assertContains('remove', $methodNames);

        // set() method details
        $setMethod = $this->findByKey($contract['methods'], 'name', 'set');
        $this->assertSame('bool', $setMethod['return_type']);
        $this->assertCount(2, $setMethod['parameters']);
        $this->assertSame('$cartId', $setMethod['parameters'][0]['name']);
        $this->assertSame('int', $setMethod['parameters'][0]['type']);
        $this->assertSame('$couponCode', $setMethod['parameters'][1]['name']);
        $this->assertSame('string', $setMethod['parameters'][1]['type']);

        // Docblock annotations
        $this->assertNotEmpty($setMethod['doc_throws']);
        $this->assertContains('\Magento\Framework\Exception\NoSuchEntityException', $setMethod['doc_throws']);

        // Evidence
        $this->assertNotEmpty($setMethod['evidence']);
        $this->assertNotEmpty($contract['evidence']);

        // DI binding correlation
        $this->assertNotEmpty($contract['di_bindings']);
        $this->assertSame('Vendor\Coupon\Model\CouponManagement', $contract['di_bindings'][0]['concrete']);
        $this->assertSame('global', $contract['di_bindings'][0]['scope']);

        // Webapi exposure correlation
        $this->assertNotEmpty($contract['webapi_routes']);
        $this->assertSame('PUT', $contract['webapi_routes'][0]['http_method']);
        $this->assertStringContainsString('/V1/carts', $contract['webapi_routes'][0]['url']);
        $this->assertSame('set', $contract['webapi_routes'][0]['service_method']);

        // Summary
        $this->assertSame(1, $result['summary']['total_service_contracts']);
        $this->assertSame(2, $result['summary']['total_methods']);
        $this->assertSame(1, $result['summary']['bound_in_di']);
        $this->assertSame(1, $result['summary']['exposed_via_webapi']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 7: DtoDataInterfaceExtractor
    // ──────────────────────────────────────────────────────────

    public function testDtoDataInterfaceExtractsGettersAndSetters(): void
    {
        $this->writeFixture('app/code/Vendor/Sales/Api/Data/OrderInterface.php', <<<'PHP'
<?php
namespace Vendor\Sales\Api\Data;

interface OrderInterface
{
    const KEY_ENTITY_ID = 'entity_id';
    const KEY_STATUS = 'status';
    const KEY_GRAND_TOTAL = 'grand_total';

    /**
     * @return int
     */
    public function getEntityId(): int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return string|null
     */
    public function getStatus(): ?string;

    /**
     * @param string|null $status
     * @return $this
     */
    public function setStatus(?string $status): self;

    /**
     * @return float
     */
    public function getGrandTotal(): float;

    /**
     * @return bool
     */
    public function isVirtual(): bool;
}
PHP
        );

        $this->writeFixture('app/code/Vendor/Sales/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Sales"/></config>
XML
        );

        $extractor = new DtoDataInterfaceExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('data_interfaces', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(1, $result['data_interfaces']);

        $dto = $result['data_interfaces'][0];
        $this->assertSame('Vendor\Sales\Api\Data\OrderInterface', $dto['interface']);
        $this->assertSame('Vendor_Sales', $dto['module']);

        // Constants
        $this->assertCount(3, $dto['constants']);
        $constNames = array_column($dto['constants'], 'name');
        $this->assertContains('KEY_ENTITY_ID', $constNames);
        $this->assertContains('KEY_STATUS', $constNames);

        // Getters: getEntityId, getStatus, getGrandTotal, isVirtual
        $this->assertCount(4, $dto['getters']);
        $getterMethods = array_column($dto['getters'], 'method');
        $this->assertContains('getEntityId', $getterMethods);
        $this->assertContains('getStatus', $getterMethods);
        $this->assertContains('getGrandTotal', $getterMethods);
        $this->assertContains('isVirtual', $getterMethods);

        // getStatus nullable check
        $statusGetter = $this->findByKey($dto['getters'], 'method', 'getStatus');
        $this->assertTrue($statusGetter['nullable']);

        // Setters: setEntityId, setStatus
        $this->assertCount(2, $dto['setters']);

        // Fields map
        $this->assertNotEmpty($dto['fields']);
        $entityIdField = $this->findByKey($dto['fields'], 'field', 'entity_id');
        $this->assertNotNull($entityIdField);
        $this->assertTrue($entityIdField['has_getter']);
        $this->assertTrue($entityIdField['has_setter']);
        $this->assertSame('int', $entityIdField['type']);

        // isVirtual should produce a field called 'virtual'
        $virtualField = $this->findByKey($dto['fields'], 'field', 'virtual');
        $this->assertNotNull($virtualField);
        $this->assertTrue($virtualField['has_getter']);
        $this->assertFalse($virtualField['has_setter']);

        // Summary
        $this->assertSame(1, $result['summary']['total_data_interfaces']);
        $this->assertSame(4, $result['summary']['total_getters']);
        $this->assertSame(2, $result['summary']['total_setters']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 3: RepositoryPatternExtractor
    // ──────────────────────────────────────────────────────────

    public function testRepositoryPatternExtractsCrudMethods(): void
    {
        $this->writeFixture('app/code/Vendor/Catalog/Api/ProductRepositoryInterface.php', <<<'PHP'
<?php
namespace Vendor\Catalog\Api;

use Magento\Framework\Api\SearchCriteriaInterface;

interface ProductRepositoryInterface
{
    /**
     * @param int $productId
     * @return \Vendor\Catalog\Api\Data\ProductInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $productId): \Vendor\Catalog\Api\Data\ProductInterface;

    /**
     * @param \Vendor\Catalog\Api\Data\ProductInterface $product
     * @return \Vendor\Catalog\Api\Data\ProductInterface
     */
    public function save(\Vendor\Catalog\Api\Data\ProductInterface $product): \Vendor\Catalog\Api\Data\ProductInterface;

    /**
     * @param \Vendor\Catalog\Api\Data\ProductInterface $product
     * @return bool
     */
    public function delete(\Vendor\Catalog\Api\Data\ProductInterface $product): bool;

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return \Vendor\Catalog\Api\Data\ProductSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): \Vendor\Catalog\Api\Data\ProductSearchResultsInterface;
}
PHP
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Vendor\Catalog\Api\ProductRepositoryInterface" type="Vendor\Catalog\Model\ProductRepository"/>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Catalog"/></config>
XML
        );

        $extractor = new RepositoryPatternExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('repositories', $result);
        $this->assertArrayHasKey('search_criteria_guide', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(1, $result['repositories']);

        $repo = $result['repositories'][0];
        $this->assertSame('Vendor\Catalog\Api\ProductRepositoryInterface', $repo['interface']);
        $this->assertSame('Product', $repo['entity_name']);
        $this->assertSame('Vendor_Catalog', $repo['module']);

        // CRUD completeness
        $this->assertTrue($repo['has_get_by_id']);
        $this->assertTrue($repo['has_save']);
        $this->assertTrue($repo['has_delete']);
        $this->assertTrue($repo['has_get_list']);
        $this->assertTrue($repo['supports_search_criteria']);
        $this->assertEquals(1.0, $repo['crud_score']);

        // Methods classified correctly
        $methods = $repo['methods'];
        $this->assertCount(4, $methods);
        $getByIdMethod = $this->findByKey($methods, 'name', 'getById');
        $this->assertSame('get_by_id', $getByIdMethod['purpose']);
        $getListMethod = $this->findByKey($methods, 'name', 'getList');
        $this->assertSame('get_list', $getListMethod['purpose']);
        $this->assertTrue($getListMethod['uses_search_criteria']);

        // DI binding
        $this->assertNotEmpty($repo['di_bindings']);
        $this->assertSame('Vendor\Catalog\Model\ProductRepository', $repo['di_bindings'][0]['concrete']);

        // SearchCriteria guide generated
        $this->assertNotEmpty($result['search_criteria_guide']);
        $guide = $result['search_criteria_guide'][0];
        $this->assertSame('Product', $guide['entity']);
        $this->assertStringContainsString('getList', $guide['get_list_signature']);

        // Summary
        $this->assertSame(1, $result['summary']['total_repositories']);
        $this->assertSame(1, $result['summary']['with_search_criteria']);
        $this->assertSame(1, $result['summary']['with_get_list']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 1: CallGraphExtractor
    // ──────────────────────────────────────────────────────────

    public function testCallGraphBuildsDelegationChains(): void
    {
        // di.xml: global preference
        $this->writeFixture('app/code/Vendor/Coupon/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Vendor\Coupon\Api\CouponManagementInterface" type="Vendor\Coupon\Model\CouponManagement"/>
    <preference for="Vendor\Coupon\Api\GuestCouponManagementInterface" type="Vendor\Coupon\Model\GuestCouponManagement"/>
</config>
XML
        );

        // webapi.xml with two routes: guest and authenticated
        $this->writeFixture('app/code/Vendor/Coupon/etc/webapi.xml', <<<'XML'
<?xml version="1.0"?>
<routes xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Webapi:etc/webapi.xsd">
    <route url="/V1/carts/:cartId/coupons/:couponCode" method="PUT">
        <service class="Vendor\Coupon\Api\CouponManagementInterface" method="set"/>
        <resources><resource ref="self"/></resources>
    </route>
    <route url="/V1/guest-carts/:cartId/coupons/:couponCode" method="PUT">
        <service class="Vendor\Coupon\Api\GuestCouponManagementInterface" method="set"/>
        <resources><resource ref="anonymous"/></resources>
    </route>
</routes>
XML
        );

        $this->writeFixture('app/code/Vendor/Coupon/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Coupon"/></config>
XML
        );

        $extractor = new CallGraphExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('delegation_chains', $result);
        $this->assertArrayHasKey('shared_concretes', $result);
        $this->assertArrayHasKey('guest_auth_pairs', $result);
        $this->assertArrayHasKey('summary', $result);

        // Should have REST entry points
        $this->assertGreaterThanOrEqual(2, count($result['delegation_chains']));

        // Find the authenticated chain
        $authChain = $this->findByKey(
            $result['delegation_chains'],
            'service_interface',
            'Vendor\Coupon\Api\CouponManagementInterface'
        );
        $this->assertNotNull($authChain);
        $this->assertSame('Vendor\Coupon\Model\CouponManagement', $authChain['final_concrete']);
        $this->assertGreaterThan(0, $authChain['delegation_depth']);

        // Find the guest chain
        $guestChain = $this->findByKey(
            $result['delegation_chains'],
            'service_interface',
            'Vendor\Coupon\Api\GuestCouponManagementInterface'
        );
        $this->assertNotNull($guestChain);
        $this->assertTrue($guestChain['is_guest']);

        // Guest/auth pair detection
        $this->assertNotEmpty($result['guest_auth_pairs']);
        $pair = $result['guest_auth_pairs'][0];
        $this->assertStringContainsString('Guest', $pair['guest_interface']);
        $this->assertStringContainsString('CouponManagement', $pair['auth_interface']);

        // Summary
        $this->assertGreaterThanOrEqual(2, $result['summary']['rest_entry_points']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['guest_auth_pairs']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 4: EntityRelationshipExtractor
    // ──────────────────────────────────────────────────────────

    public function testEntityRelationshipExtractsTableMappings(): void
    {
        // ResourceModel with _init()
        $this->writeFixture('app/code/Vendor/Sales/Model/ResourceModel/Order.php', <<<'PHP'
<?php
namespace Vendor\Sales\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Order extends AbstractDb
{
    protected $_connectionName = 'sales';

    protected function _construct()
    {
        $this->_init('sales_order', 'entity_id');
    }
}
PHP
        );

        // Collection
        $this->writeFixture('app/code/Vendor/Sales/Model/ResourceModel/Order/Collection.php', <<<'PHP'
<?php
namespace Vendor\Sales\Model\ResourceModel\Order;

use Vendor\Sales\Model\Order as OrderModel;
use Vendor\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(OrderModel::class, OrderResource::class);
    }
}
PHP
        );

        // db_schema.xml with foreign key
        $this->writeFixture('app/code/Vendor/Sales/etc/db_schema.xml', <<<'XML'
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">
    <table name="sales_order" resource="sales" engine="innodb">
        <column xsi:type="int" name="entity_id" unsigned="true" identity="true" nullable="false"/>
        <column xsi:type="varchar" name="status" nullable="true" length="32"/>
    </table>
    <table name="sales_order_item" resource="sales" engine="innodb">
        <column xsi:type="int" name="item_id" unsigned="true" identity="true" nullable="false"/>
        <column xsi:type="int" name="order_id" unsigned="true" nullable="false"/>
        <constraint xsi:type="foreign" referenceId="SALES_ORDER_ITEM_ORDER_ID" table="sales_order_item" column="order_id" referenceTable="sales_order" referenceColumn="entity_id" onDelete="CASCADE"/>
    </table>
</schema>
XML
        );

        // Model with status constants
        $this->writeFixture('app/code/Vendor/Sales/Model/Order.php', <<<'PHP'
<?php
namespace Vendor\Sales\Model;

class Order extends \Magento\Framework\Model\AbstractModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETE = 'complete';
    const STATE_NEW = 'new';
    const STATE_CLOSED = 'closed';
}
PHP
        );

        $this->writeFixture('app/code/Vendor/Sales/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Sales"/></config>
XML
        );

        $extractor = new EntityRelationshipExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('entities', $result);
        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('foreign_keys', $result);
        $this->assertArrayHasKey('domain_invariants', $result);
        $this->assertArrayHasKey('summary', $result);

        // Should find the Order resource model → table mapping
        $this->assertGreaterThanOrEqual(1, count($result['entities']));
        $orderEntity = $this->findByKey($result['entities'], 'table', 'sales_order');
        $this->assertNotNull($orderEntity, 'Order entity should be found');
        $this->assertSame('entity_id', $orderEntity['id_field']);
        $this->assertSame('sales', $orderEntity['connection']);

        // Foreign keys
        $this->assertCount(1, $result['foreign_keys']);
        $fk = $result['foreign_keys'][0];
        $this->assertSame('sales_order_item', $fk['from_table']);
        $this->assertSame('order_id', $fk['from_column']);
        $this->assertSame('sales_order', $fk['to_table']);
        $this->assertSame('entity_id', $fk['to_column']);
        $this->assertSame('CASCADE', $fk['on_delete']);

        // Relationships
        $this->assertGreaterThanOrEqual(1, count($result['relationships']));
        $rel = $result['relationships'][0];
        $this->assertSame('sales_order_item', $rel['from_table']);
        $this->assertSame('sales_order', $rel['to_table']);
        $this->assertStringContainsString('CASCADE', $rel['note']);

        // Domain invariants (status constants from Model)
        $this->assertNotEmpty($result['domain_invariants']);
        $orderInvariant = $this->findByKey($result['domain_invariants'], 'class', 'Vendor\Sales\Model\Order');
        $this->assertNotNull($orderInvariant);
        $constNames = array_column($orderInvariant['status_constants'], 'name');
        $this->assertContains('STATUS_PENDING', $constNames);
        $this->assertContains('STATE_NEW', $constNames);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 5: PluginSeamTimingExtractor
    // ──────────────────────────────────────────────────────────

    public function testPluginSeamTimingExtractsChainWithTiming(): void
    {
        // di.xml with multiple plugins on one class
        $this->writeFixture('app/code/Vendor/Checkout/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Vendor\Checkout\Model\Cart">
        <plugin name="vendor_plugin_a" type="Vendor\Checkout\Plugin\CartPluginA" sortOrder="10"/>
        <plugin name="vendor_plugin_b" type="Vendor\Checkout\Plugin\CartPluginB" sortOrder="20"/>
    </type>
</config>
XML
        );

        // Plugin A: around plugin that calls proceed
        $this->writeFixture('app/code/Vendor/Checkout/Plugin/CartPluginA.php', <<<'PHP'
<?php
namespace Vendor\Checkout\Plugin;

class CartPluginA
{
    public function aroundAddItem($subject, callable $proceed, $item)
    {
        $item->setData('modified', true);
        $result = $proceed($item);
        return $result;
    }

    public function beforeRemoveItem($subject, $itemId)
    {
        return [$itemId];
    }
}
PHP
        );

        // Plugin B: after plugin
        $this->writeFixture('app/code/Vendor/Checkout/Plugin/CartPluginB.php', <<<'PHP'
<?php
namespace Vendor\Checkout\Plugin;

class CartPluginB
{
    public function afterAddItem($subject, $result)
    {
        return $result;
    }
}
PHP
        );

        $this->writeFixture('app/code/Vendor/Checkout/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Checkout"/></config>
XML
        );

        $extractor = new PluginSeamTimingExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('seams', $result);
        $this->assertArrayHasKey('plugin_declarations', $result);
        $this->assertArrayHasKey('summary', $result);

        // Should have 2 plugin declarations
        $this->assertCount(2, $result['plugin_declarations']);

        // Should have seams for addItem and removeItem
        $this->assertGreaterThanOrEqual(2, count($result['seams']));

        // addItem seam should have around + after plugins
        $addItemSeam = $this->findByKey($result['seams'], 'target_method', 'addItem');
        $this->assertNotNull($addItemSeam, 'addItem seam should exist');
        $this->assertNotEmpty($addItemSeam['around_plugins']);
        $this->assertNotEmpty($addItemSeam['after_plugins']);
        $this->assertTrue($addItemSeam['has_around']);
        $this->assertGreaterThanOrEqual(2, $addItemSeam['total_plugins']);

        // Execution sequence should be present
        $this->assertNotEmpty($addItemSeam['execution_sequence']);
        $phases = array_column($addItemSeam['execution_sequence'], 'phase');
        $this->assertContains('around_before_proceed', $phases);
        $this->assertContains('original_method', $phases);
        $this->assertContains('after', $phases);

        // Risk assessment
        $this->assertArrayHasKey('risk_score', $addItemSeam);
        $this->assertArrayHasKey('risk_level', $addItemSeam);
        $this->assertArrayHasKey('recommendations', $addItemSeam);

        // removeItem seam should have before plugin only
        $removeItemSeam = $this->findByKey($result['seams'], 'target_method', 'removeItem');
        $this->assertNotNull($removeItemSeam, 'removeItem seam should exist');
        $this->assertNotEmpty($removeItemSeam['before_plugins']);
        $this->assertFalse($removeItemSeam['has_around']);

        // Summary
        $this->assertSame(2, $result['summary']['total_plugin_declarations']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['plugins_with_around']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 6: SafeApiMatrixExtractor
    // ──────────────────────────────────────────────────────────

    public function testSafeApiMatrixClassifiesMethodTiers(): void
    {
        // Api interface
        $this->writeFixture('app/code/Vendor/Catalog/Api/ProductServiceInterface.php', <<<'PHP'
<?php
namespace Vendor\Catalog\Api;

interface ProductServiceInterface
{
    public function getById(int $id): array;
    public function save(array $data): bool;
}
PHP
        );

        // Concrete class implementing the interface, with extra methods and deprecation
        $this->writeFixture('app/code/Vendor/Catalog/Model/ProductService.php', <<<'PHP'
<?php
namespace Vendor\Catalog\Model;

use Vendor\Catalog\Api\ProductServiceInterface;

/**
 * @api
 */
class ProductService implements ProductServiceInterface
{
    public function getById(int $id): array
    {
        return [];
    }

    public function save(array $data): bool
    {
        return true;
    }

    /**
     * @deprecated since 2.4.0
     * @see \Vendor\Catalog\Api\ProductServiceInterface::getById
     */
    public function loadById(int $id): array
    {
        return $this->getById($id);
    }

    protected function internalHelper(): void
    {
    }
}
PHP
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Catalog"/></config>
XML
        );

        $extractor = new SafeApiMatrixExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('class_matrix', $result);
        $this->assertArrayHasKey('deprecated_methods', $result);
        $this->assertArrayHasKey('summary', $result);

        // Should have the concrete class analyzed
        $this->assertGreaterThanOrEqual(1, count($result['class_matrix']));

        $classEntry = $this->findByKey($result['class_matrix'], 'class', 'Vendor\Catalog\Model\ProductService');
        $this->assertNotNull($classEntry, 'ProductService should be in the matrix');
        $this->assertTrue($classEntry['is_api_class']);

        // Method classification
        $methods = $classEntry['methods'];
        $getById = $this->findByKey($methods, 'method', 'getById');
        $this->assertNotNull($getById);
        $this->assertSame('api_interface', $getById['stability_tier']);
        $this->assertSame(1.0, $getById['stability_score']);

        $loadById = $this->findByKey($methods, 'method', 'loadById');
        $this->assertNotNull($loadById);
        $this->assertTrue($loadById['is_deprecated']);
        $this->assertSame('deprecated', $loadById['stability_tier']);
        $this->assertSame('2.4.0', $loadById['deprecated_since']);
        $this->assertNotNull($loadById['replacement']);

        // Deprecated methods list
        $this->assertNotEmpty($result['deprecated_methods']);

        // Summary
        $this->assertGreaterThanOrEqual(1, $result['summary']['total_deprecated']);
    }

    // ──────────────────────────────────────────────────────────
    // Extractor 8: ImplementationPatternExtractor
    // ──────────────────────────────────────────────────────────

    public function testImplementationPatternExtractsConstructorDeps(): void
    {
        // Api Interface
        $this->writeFixture('app/code/Vendor/Catalog/Api/ProductRepositoryInterface.php', <<<'PHP'
<?php
namespace Vendor\Catalog\Api;

interface ProductRepositoryInterface
{
    public function getById(int $id): array;
    public function save(array $data): bool;
}
PHP
        );

        // Concrete implementation
        $this->writeFixture('app/code/Vendor/Catalog/Model/ProductRepository.php', <<<'PHP'
<?php
namespace Vendor\Catalog\Model;

use Vendor\Catalog\Api\ProductRepositoryInterface;
use Vendor\Catalog\Model\ResourceModel\Product as ProductResource;
use Vendor\Catalog\Api\Data\ProductInterfaceFactory;
use Psr\Log\LoggerInterface;

class ProductRepository implements ProductRepositoryInterface
{
    private ProductResource $resource;
    private ProductInterfaceFactory $productFactory;
    private LoggerInterface $logger;

    public function __construct(
        ProductResource $resource,
        ProductInterfaceFactory $productFactory,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->productFactory = $productFactory;
        $this->logger = $logger;
    }

    public function getById(int $id): array
    {
        return [];
    }

    public function save(array $data): bool
    {
        return true;
    }

    public function clearCache(): void
    {
    }
}
PHP
        );

        // di.xml binding
        $this->writeFixture('app/code/Vendor/Catalog/etc/di.xml', <<<'XML'
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Vendor\Catalog\Api\ProductRepositoryInterface" type="Vendor\Catalog\Model\ProductRepository"/>
</config>
XML
        );

        $this->writeFixture('app/code/Vendor/Catalog/etc/module.xml', <<<'XML'
<?xml version="1.0"?>
<config><module name="Vendor_Catalog"/></config>
XML
        );

        // composer.json for PSR-4 so ModuleResolver can find the class file
        $this->writeFixture('app/code/Vendor/Catalog/composer.json', json_encode([
            'name' => 'vendor/module-catalog',
            'autoload' => [
                'psr-4' => [
                    'Vendor\\Catalog\\' => '',
                ],
            ],
        ]));

        $extractor = new ImplementationPatternExtractor();
        $result = $this->runExtractor($extractor);

        $this->assertArrayHasKey('implementations', $result);
        $this->assertArrayHasKey('pattern_distribution', $result);
        $this->assertArrayHasKey('summary', $result);

        $this->assertGreaterThanOrEqual(1, count($result['implementations']));

        $impl = $this->findByKey($result['implementations'], 'concrete_class', 'Vendor\Catalog\Model\ProductRepository');
        $this->assertNotNull($impl, 'ProductRepository implementation should be found');
        $this->assertSame('Vendor\Catalog\Api\ProductRepositoryInterface', $impl['interface']);

        // Constructor dependencies
        $this->assertCount(3, $impl['constructor_dependencies']);
        $deps = $impl['constructor_dependencies'];

        $resourceDep = $this->findByKey($deps, 'name', '$resource');
        $this->assertNotNull($resourceDep);

        $factoryDep = $this->findByKey($deps, 'name', '$productFactory');
        $this->assertNotNull($factoryDep);
        $this->assertTrue($factoryDep['is_factory']);

        $loggerDep = $this->findByKey($deps, 'name', '$logger');
        $this->assertNotNull($loggerDep);
        $this->assertTrue($loggerDep['is_logger']);

        // Implemented methods vs extra methods
        $this->assertCount(2, $impl['implemented_methods']); // getById, save
        $this->assertCount(1, $impl['extra_methods']); // clearCache

        // Patterns detected
        $this->assertContains('factory', $impl['patterns']);
        $this->assertContains('resource_model_delegation', $impl['patterns']);

        // Summary
        $this->assertGreaterThanOrEqual(1, $result['summary']['total_implementations']);
        $this->assertSame(1, $result['summary']['with_factory_pattern']);
    }

    // ──────────────────────────────────────────────────────────
    // Determinism: all extractors produce sorted output
    // ──────────────────────────────────────────────────────────

    public function testServiceContractDeterminism(): void
    {
        $this->writeFixture('app/code/Vendor/ModuleB/Api/BetaInterface.php', <<<'PHP'
<?php
namespace Vendor\ModuleB\Api;
interface BetaInterface { public function doB(): void; }
PHP
        );
        $this->writeFixture('app/code/Vendor/ModuleA/Api/AlphaInterface.php', <<<'PHP'
<?php
namespace Vendor\ModuleA\Api;
interface AlphaInterface { public function doA(): void; }
PHP
        );
        $this->writeFixture('app/code/Vendor/ModuleA/etc/module.xml', '<config><module name="Vendor_ModuleA"/></config>');
        $this->writeFixture('app/code/Vendor/ModuleB/etc/module.xml', '<config><module name="Vendor_ModuleB"/></config>');

        $extractor = new ServiceContractExtractor();
        $r1 = $this->runExtractor($extractor);
        $r2 = $this->runExtractor($extractor);

        $this->assertSame(
            json_encode($r1['contracts']),
            json_encode($r2['contracts']),
            'Service contract output must be deterministic across runs'
        );

        // Alpha before Beta (sorted)
        $this->assertStringContainsString('Alpha', $r1['contracts'][0]['interface']);
        $this->assertStringContainsString('Beta', $r1['contracts'][1]['interface']);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Write a fixture file relative to the tmp directory.
     */
    private function writeFixture(string $relativePath, string $content): void
    {
        $fullPath = $this->tmpDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, $content);
    }

    /**
     * Wire up a CompilationContext and run an extractor.
     */
    private function runExtractor(object $extractor): array
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

        $extractor->setContext($context);
        return $extractor->extract($this->tmpDir, $scopes);
    }

    /**
     * Find a record in an array by matching a key's value.
     */
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

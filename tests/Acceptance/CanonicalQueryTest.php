<?php

declare(strict_types=1);

namespace MageContext\Tests\Acceptance;

use MageContext\Output\IndexBuilder;
use MageContext\Output\ScenarioBundleGenerator;
use MageContext\Output\ScenarioSeedResolver;
use PHPUnit\Framework\TestCase;

/**
 * D.2: Acceptance tests — 5 canonical queries that the compiled bundle must answer.
 *
 * Each test constructs realistic extractor output, runs the IndexBuilder (and
 * ScenarioBundleGenerator where relevant), then asserts the reverse indexes
 * can answer the query in O(1).
 *
 * These are the "if this breaks, the bundle is useless" tests.
 */
class CanonicalQueryTest extends TestCase
{
    /**
     * Query 1: controller → plugin chain
     *
     * "Given route adminhtml/catalog/product, what plugins intercept its controller?"
     *
     * Path: route_map.routes[route_id] → controller class → reverse_index.by_class[class].plugins_on
     */
    public function testControllerToPluginChain(): void
    {
        $allData = $this->baseData();

        // Route pointing to a controller
        $allData['route_map'] = [
            'routes' => [
                [
                    'route_id' => 'adminhtml/catalog/product',
                    'area' => 'adminhtml',
                    'front_name' => 'catalog',
                    'declared_by' => 'Magento_Catalog',
                    'evidence' => [],
                ],
            ],
        ];

        // Symbol index has the controller
        $allData['symbol_index'] = [
            'symbols' => [
                [
                    'class_id' => 'magento\catalog\controller\adminhtml\product\index',
                    'fqcn' => 'Magento\Catalog\Controller\Adminhtml\Product\Index',
                    'symbol_type' => 'class',
                    'file_id' => 'app/code/Magento/Catalog/Controller/Adminhtml/Product/Index.php',
                    'module_id' => 'Magento_Catalog',
                    'extends' => null,
                    'implements' => [],
                    'public_methods' => ['execute'],
                    'is_abstract' => false,
                    'is_final' => false,
                    'line' => 10,
                ],
            ],
        ];

        // Plugin on the controller
        $allData['plugin_chains'] = [
            'Magento\Catalog\Controller\Adminhtml\Product\Index' => [
                'plugins' => [
                    [
                        'plugin_class' => 'Vendor\Module\Plugin\ProductControllerPlugin',
                        'type' => 'after',
                        'sort_order' => 10,
                        'module' => 'Vendor_Module',
                    ],
                    [
                        'plugin_class' => 'Vendor\Other\Plugin\AnotherPlugin',
                        'type' => 'before',
                        'sort_order' => 20,
                        'module' => 'Vendor_Other',
                    ],
                ],
            ],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        // Query: route → by_route → controller → by_class → plugins_on
        $route = $result['by_route']['adminhtml/catalog/product'] ?? null;
        $this->assertNotNull($route, 'Route must exist in by_route index');

        $controllerClassId = 'magento\catalog\controller\adminhtml\product\index';
        $classEntry = $result['by_class'][$controllerClassId] ?? null;
        $this->assertNotNull($classEntry, 'Controller class must exist in by_class index');
        $this->assertCount(2, $classEntry['plugins_on'], 'Controller must have 2 plugins');
        $this->assertSame('after', $classEntry['plugins_on'][0]['type']);
        $this->assertSame('before', $classEntry['plugins_on'][1]['type']);
    }

    /**
     * Query 2: interface → resolved implementation
     *
     * "Given interface ProductRepositoryInterface, what concrete class handles it?"
     *
     * Path: reverse_index.by_class[interface].di_resolutions → resolved_to
     */
    public function testInterfaceToResolvedImpl(): void
    {
        $allData = $this->baseData();

        $allData['symbol_index'] = [
            'symbols' => [
                [
                    'class_id' => 'magento\catalog\api\productrepositoryinterface',
                    'fqcn' => 'Magento\Catalog\Api\ProductRepositoryInterface',
                    'symbol_type' => 'interface',
                    'file_id' => 'app/code/Magento/Catalog/Api/ProductRepositoryInterface.php',
                    'module_id' => 'Magento_Catalog',
                    'extends' => null,
                    'implements' => [],
                    'public_methods' => ['getById', 'save', 'delete'],
                    'is_abstract' => false,
                    'is_final' => false,
                    'line' => 5,
                ],
            ],
        ];

        $allData['di_resolution_map'] = [
            'resolutions' => [
                [
                    'interface' => 'Magento\Catalog\Api\ProductRepositoryInterface',
                    'final_resolved_type' => 'Magento\Catalog\Model\ProductRepository',
                    'area' => 'global',
                    'confidence' => 1.0,
                    'evidence' => [],
                ],
                [
                    'interface' => 'Magento\Catalog\Api\ProductRepositoryInterface',
                    'final_resolved_type' => 'Vendor\Module\Model\CustomProductRepository',
                    'area' => 'frontend',
                    'confidence' => 0.8,
                    'evidence' => [],
                ],
            ],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        $classId = 'magento\catalog\api\productrepositoryinterface';
        $entry = $result['by_class'][$classId] ?? null;
        $this->assertNotNull($entry);
        $this->assertSame('interface', $entry['symbol_type']);
        $this->assertCount(2, $entry['di_resolutions'], 'Interface must have 2 DI resolutions');
        $this->assertSame('global', $entry['di_resolutions'][0]['area']);
        $this->assertSame('frontend', $entry['di_resolutions'][1]['area']);

        // Verify resolved_to class is captured
        $resolvedClasses = array_column($entry['di_resolutions'], 'resolved_to');
        $this->assertContains('magento\catalog\model\productrepository', $resolvedClasses);
        $this->assertContains('vendor\module\model\customproductrepository', $resolvedClasses);
    }

    /**
     * Query 3: event → listeners
     *
     * "Given event catalog_product_save_after, who listens to it and from which modules?"
     *
     * Path: reverse_index.by_event[event_id] → observers[] with module_id
     */
    public function testEventToListeners(): void
    {
        $allData = $this->baseData();

        $allData['event_graph'] = [
            'event_graph' => [
                [
                    'event_id' => 'catalog_product_save_after',
                    'event' => 'catalog_product_save_after',
                    'declared_by' => 'Magento_Catalog',
                    'listeners' => [
                        [
                            'observer_class' => 'Vendor\Module\Observer\ProductSaveObserver',
                            'module' => 'Vendor_Module',
                            'method' => 'execute',
                        ],
                        [
                            'observer_class' => 'Vendor\Search\Observer\ReindexProduct',
                            'module' => 'Vendor_Search',
                            'method' => 'execute',
                        ],
                        [
                            'observer_class' => 'Vendor\Cache\Observer\InvalidateCache',
                            'module' => 'Vendor_Cache',
                            'method' => 'execute',
                        ],
                    ],
                    'listener_count' => 3,
                    'cross_module_listeners' => 3,
                    'risk_score' => 0.6,
                ],
            ],
            'observers' => [],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        $event = $result['by_event']['catalog_product_save_after'] ?? null;
        $this->assertNotNull($event, 'Event must exist in by_event index');
        $this->assertSame(3, $event['observer_count']);
        $this->assertSame(3, $event['cross_module_count']);
        $this->assertSame(0.6, $event['risk_score']);

        // Verify each observer has correct module
        $observerModules = array_column($event['observers'], 'module_id');
        $this->assertContains('Vendor_Module', $observerModules);
        $this->assertContains('Vendor_Search', $observerModules);
        $this->assertContains('Vendor_Cache', $observerModules);
    }

    /**
     * Query 4: module → dependents
     *
     * "Given module Magento_Catalog, what other modules depend on it?"
     *
     * Path: reverse_index.by_module[module_id] → {classes, routes, plugins, events, debt}
     */
    public function testModuleToDependents(): void
    {
        $allData = $this->baseData();

        $allData['modules'] = [
            'modules' => [
                ['name' => 'Magento_Catalog', 'id' => 'Magento_Catalog', 'type' => 'magento_module', 'path' => 'app/code/Magento/Catalog'],
                ['name' => 'Vendor_Module', 'id' => 'Vendor_Module', 'type' => 'magento_module', 'path' => 'app/code/Vendor/Module'],
            ],
        ];

        $allData['symbol_index'] = [
            'symbols' => [
                ['class_id' => 'magento\catalog\model\product', 'fqcn' => 'Magento\Catalog\Model\Product', 'symbol_type' => 'class', 'file_id' => 'f1.php', 'module_id' => 'Magento_Catalog'],
                ['class_id' => 'magento\catalog\model\category', 'fqcn' => 'Magento\Catalog\Model\Category', 'symbol_type' => 'class', 'file_id' => 'f2.php', 'module_id' => 'Magento_Catalog'],
                ['class_id' => 'vendor\module\model\custom', 'fqcn' => 'Vendor\Module\Model\Custom', 'symbol_type' => 'class', 'file_id' => 'f3.php', 'module_id' => 'Vendor_Module'],
            ],
        ];

        $allData['file_index'] = [
            'files' => [
                ['file_id' => 'f1.php', 'module_id' => 'Magento_Catalog', 'layer' => 'domain', 'file_type' => 'php'],
                ['file_id' => 'f2.php', 'module_id' => 'Magento_Catalog', 'layer' => 'domain', 'file_type' => 'php'],
                ['file_id' => 'f3.php', 'module_id' => 'Vendor_Module', 'layer' => 'domain', 'file_type' => 'php'],
                ['file_id' => 'f4.xml', 'module_id' => 'Magento_Catalog', 'layer' => 'framework', 'file_type' => 'xml'],
            ],
        ];

        $allData['route_map'] = [
            'routes' => [
                ['route_id' => 'frontend/catalog/product', 'area' => 'frontend', 'declared_by' => 'Magento_Catalog', 'evidence' => []],
            ],
        ];

        $allData['cron_map'] = [
            'cron_jobs' => [
                ['cron_id' => 'cron::catalog_reindex', 'group' => 'default', 'declared_by' => 'Magento_Catalog', 'module' => 'Magento_Catalog', 'evidence' => []],
            ],
        ];

        $allData['architectural_debt'] = [
            'debt_items' => [
                ['type' => 'god_module', 'message' => 'Too many classes', 'modules' => ['Magento_Catalog']],
            ],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        // Query: by_module for Magento_Catalog
        $mod = $result['by_module']['Magento_Catalog'] ?? null;
        $this->assertNotNull($mod, 'Module must exist in by_module index');
        $this->assertSame('Magento_Catalog', $mod['module_id']);

        // Verify file count
        $this->assertCount(3, $mod['files'], 'Magento_Catalog should own 3 files (f1, f2, f4)');

        // Verify class count
        $this->assertCount(2, $mod['classes'], 'Magento_Catalog should have 2 classes');

        // Verify routes
        $this->assertContains('frontend/catalog/product', $mod['routes']);

        // Verify crons
        $this->assertContains('cron::catalog_reindex', $mod['crons']);

        // Verify debt
        $this->assertCount(1, $mod['debt_items']);
        $this->assertSame('god_module', $mod['debt_items'][0]['type']);

        // Verify other module
        $vendor = $result['by_module']['Vendor_Module'] ?? null;
        $this->assertNotNull($vendor);
        $this->assertCount(1, $vendor['classes']);
        $this->assertCount(1, $vendor['files']);
    }

    /**
     * Query 5: hotspot → touchpoints
     *
     * "Given the top hotspot module, what are all the ways it's touched?"
     *
     * Path: by_module[module_id] → {plugins_declared, events_observed, cli_commands, routes, crons}
     * Cross-ref with: symbol_index for class details, file_index for file layers
     */
    public function testHotspotToTouchpoints(): void
    {
        $allData = $this->baseData();

        $allData['modules'] = [
            'modules' => [
                ['name' => 'Vendor_Checkout', 'id' => 'Vendor_Checkout', 'type' => 'magento_module', 'path' => 'app/code/Vendor/Checkout'],
            ],
        ];

        $allData['symbol_index'] = [
            'symbols' => [
                ['class_id' => 'vendor\checkout\controller\index\index', 'fqcn' => 'Vendor\Checkout\Controller\Index\Index', 'symbol_type' => 'class', 'file_id' => 'c1.php', 'module_id' => 'Vendor_Checkout'],
                ['class_id' => 'vendor\checkout\observer\orderobserver', 'fqcn' => 'Vendor\Checkout\Observer\OrderObserver', 'symbol_type' => 'class', 'file_id' => 'c2.php', 'module_id' => 'Vendor_Checkout'],
            ],
        ];

        $allData['file_index'] = [
            'files' => [
                ['file_id' => 'c1.php', 'module_id' => 'Vendor_Checkout', 'layer' => 'presentation', 'file_type' => 'php'],
                ['file_id' => 'c2.php', 'module_id' => 'Vendor_Checkout', 'layer' => 'infrastructure', 'file_type' => 'php'],
            ],
        ];

        // Events observed by this module
        $allData['event_graph'] = [
            'event_graph' => [
                [
                    'event_id' => 'sales_order_place_after',
                    'event' => 'sales_order_place_after',
                    'declared_by' => 'Magento_Sales',
                    'listeners' => [
                        ['observer_class' => 'Vendor\Checkout\Observer\OrderObserver', 'module' => 'Vendor_Checkout', 'method' => 'execute'],
                    ],
                    'listener_count' => 1,
                    'cross_module_listeners' => 1,
                    'risk_score' => 0.3,
                ],
            ],
            'observers' => [],
        ];

        // Plugins declared by this module
        $allData['plugin_chains'] = [
            'Magento\Checkout\Model\Cart' => [
                'plugins' => [
                    ['plugin_class' => 'Vendor\Checkout\Plugin\CartPlugin', 'type' => 'around', 'sort_order' => 10, 'module' => 'Vendor_Checkout'],
                ],
            ],
        ];

        // CLI commands
        $allData['cli_commands'] = [
            'commands' => [
                ['command_name' => 'checkout:cleanup', 'class' => 'Vendor\Checkout\Console\Cleanup', 'declared_by' => 'Vendor_Checkout', 'module' => 'Vendor_Checkout', 'evidence' => []],
            ],
        ];

        // Routes
        $allData['route_map'] = [
            'routes' => [
                ['route_id' => 'frontend/checkout/cart', 'area' => 'frontend', 'declared_by' => 'Vendor_Checkout', 'module' => 'Vendor_Checkout', 'evidence' => []],
            ],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        $mod = $result['by_module']['Vendor_Checkout'] ?? null;
        $this->assertNotNull($mod);

        // Classes
        $this->assertCount(2, $mod['classes']);

        // Files
        $this->assertCount(2, $mod['files']);

        // Events observed
        $this->assertContains('sales_order_place_after', $mod['events_observed']);

        // Plugins declared
        $this->assertCount(1, $mod['plugins_declared']);
        $this->assertSame('Vendor\Checkout\Plugin\CartPlugin', $mod['plugins_declared'][0]['plugin_class']);

        // CLI commands
        $this->assertContains('checkout:cleanup', $mod['cli_commands']);

        // Routes
        $this->assertContains('frontend/checkout/cart', $mod['routes']);

        // Cross-check: observer class shows up in by_class with events_observed
        $observerClass = $result['by_class']['vendor\checkout\observer\orderobserver'] ?? null;
        $this->assertNotNull($observerClass);
        $this->assertContains('sales_order_place_after', $observerClass['events_observed']);
    }

    /**
     * Bonus: Scenario coverage invariant — matched + unmatched = total_scenarios
     */
    public function testScenarioCoverageInvariant(): void
    {
        $allData = $this->baseData();

        $allData['modules'] = [
            'modules' => [
                ['name' => 'Vendor_Module', 'id' => 'Vendor_Module', 'type' => 'magento_module', 'path' => 'app/code/Vendor/Module'],
            ],
        ];

        $allData['route_map'] = [
            'routes' => [
                ['route_id' => 'frontend/test/route', 'area' => 'frontend', 'declared_by' => 'Vendor_Module', 'evidence' => []],
            ],
        ];

        $allData['cron_map'] = ['cron_jobs' => []];
        $allData['cli_commands'] = ['commands' => []];
        $allData['api_surface'] = ['endpoints' => []];

        $allData['execution_paths'] = [
            'paths' => [
                ['scenario' => 'frontend.controller.test.action1', 'type' => 'controller', 'area' => 'frontend', 'entry_class' => 'Vendor\Module\Controller\Test\Action1', 'module' => 'Vendor_Module', 'entry_point' => 'Vendor\Module\Controller\Test\Action1::execute'],
                ['scenario' => 'frontend.controller.test.action2', 'type' => 'controller', 'area' => 'frontend', 'entry_class' => 'Vendor\Module\Controller\Test\Action2', 'module' => 'Vendor_Module', 'entry_point' => 'Vendor\Module\Controller\Test\Action2::execute'],
            ],
        ];

        // Provide required data for ScenarioBundleGenerator
        $allData['modifiability'] = ['modules' => []];
        $allData['architectural_debt'] = ['debt_items' => []];
        $allData['layer_classification'] = ['violations' => []];
        $allData['performance'] = ['indicators' => []];
        $allData['hotspot_ranking'] = ['rankings' => []];

        // Build reverse index first
        $builder = new IndexBuilder();
        $allData['reverse_index'] = $builder->build($allData);

        $generator = new ScenarioBundleGenerator();
        $scenarios = $generator->generate($allData);
        $coverage = $generator->getCoverageReport();

        $this->assertSame(
            $coverage['total_scenarios'],
            $coverage['matched'] + $coverage['unmatched'],
            'matched + unmatched must equal total_scenarios'
        );

        $this->assertSame(2, $coverage['total_scenarios']);
        $this->assertGreaterThanOrEqual(0, $coverage['matched']);

        // Every unmatched detail must have a reason_code
        foreach ($coverage['unmatched_details'] as $detail) {
            $this->assertArrayHasKey('reason_code', $detail);
            $this->assertNotEmpty($detail['reason_code']);
            $this->assertArrayHasKey('scenario_id', $detail);
            $this->assertNotEmpty($detail['scenario_id']);
        }
    }

    /**
     * Bonus: Index cross-consistency (BundleValidator invariants)
     */
    public function testIndexCrossConsistency(): void
    {
        $allData = $this->baseData();

        $allData['modules'] = [
            'modules' => [
                ['name' => 'Mod_A', 'id' => 'Mod_A', 'type' => 'magento_module', 'path' => 'a'],
            ],
        ];

        $allData['symbol_index'] = [
            'symbols' => [
                ['class_id' => 'mod\a\model\foo', 'fqcn' => 'Mod\A\Model\Foo', 'symbol_type' => 'class', 'file_id' => 'a/Model/Foo.php', 'module_id' => 'Mod_A'],
            ],
        ];

        $allData['file_index'] = [
            'files' => [
                ['file_id' => 'a/Model/Foo.php', 'module_id' => 'Mod_A', 'layer' => 'domain', 'file_type' => 'php'],
            ],
        ];

        $builder = new IndexBuilder();
        $result = $builder->build($allData);

        // Every by_class file_id must exist in file_index
        $fileIds = array_column($allData['file_index']['files'], 'file_id');
        foreach ($result['by_class'] as $classId => $entry) {
            $this->assertContains($entry['file_id'], $fileIds, "Class {$classId} file_id must exist in file_index");
        }

        // Every by_module module_id must exist in modules
        $moduleIds = array_column($allData['modules']['modules'], 'name');
        foreach ($result['by_module'] as $modId => $entry) {
            $this->assertContains($modId, $moduleIds, "Module {$modId} must exist in modules.json");
        }

        // Summary counts must match actual index sizes
        $this->assertSame(count($result['by_class']), $result['summary']['indexed_classes']);
        $this->assertSame(count($result['by_module']), $result['summary']['indexed_modules']);
        $this->assertSame(count($result['by_event']), $result['summary']['indexed_events']);
        $this->assertSame(count($result['by_route']), $result['summary']['indexed_routes']);
    }

    /**
     * Provide minimal base data structure with all required keys.
     */
    private function baseData(): array
    {
        return [
            'modules' => ['modules' => []],
            'symbol_index' => ['symbols' => []],
            'file_index' => ['files' => []],
            'dependencies' => ['edges' => []],
            'plugin_chains' => [],
            'event_graph' => ['event_graph' => [], 'observers' => []],
            'di_resolution_map' => ['resolutions' => []],
            'route_map' => ['routes' => []],
            'cron_map' => ['cron_jobs' => []],
            'cli_commands' => ['commands' => []],
            'api_surface' => ['endpoints' => []],
            'architectural_debt' => ['debt_items' => []],
            'custom_deviations' => ['deviations' => []],
            'layer_classification' => ['violations' => []],
            'modifiability' => ['modules' => []],
            'performance' => ['indicators' => []],
            'hotspot_ranking' => ['rankings' => []],
            'reverse_index' => ['by_module' => [], 'by_class' => [], 'by_event' => [], 'by_route' => []],
        ];
    }
}

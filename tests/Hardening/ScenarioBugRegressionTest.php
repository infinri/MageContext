<?php

declare(strict_types=1);

namespace MageContext\Tests\Hardening;

use MageContext\Extractor\Magento\DeviationExtractor;
use MageContext\Output\ScenarioBundleGenerator;
use MageContext\Output\ScenarioSeedResolver;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for three scenario/deviation bugs:
 *
 * Bug 1: scenario_id collision — 111 unmatched controller scenarios shared the same hash
 * Bug 2: Zero-count template deviations emitted for Magento_* dirs with no .phtml files
 * Bug 3: entry_point.class always empty — ExecutionPathExtractor didn't emit entry_class field
 */
class ScenarioBugRegressionTest extends TestCase
{
    /**
     * Bug 1 regression: Fallback scenario_ids must be unique per scenario.
     *
     * Previously, unmatched controllers all hashed {"type":"controller","class":""}
     * producing the same SHA1. Now the scenario name is included in the hash.
     */
    public function testFallbackScenarioIdsAreUnique(): void
    {
        $id1 = ScenarioSeedResolver::scenarioId([
            'type' => 'controller',
            'class' => '',
            'scenario' => 'frontend.controller.cart.add',
        ]);

        $id2 = ScenarioSeedResolver::scenarioId([
            'type' => 'controller',
            'class' => '',
            'scenario' => 'frontend.controller.order.view',
        ]);

        $id3 = ScenarioSeedResolver::scenarioId([
            'type' => 'controller',
            'class' => '',
            'scenario' => 'adminhtml.controller.index.save',
        ]);

        $this->assertNotEquals($id1, $id2, 'Different scenarios must produce different fallback IDs');
        $this->assertNotEquals($id1, $id3, 'Different scenarios must produce different fallback IDs');
        $this->assertNotEquals($id2, $id3, 'Different scenarios must produce different fallback IDs');
    }

    /**
     * Bug 1 regression: Same scenario name must still produce deterministic ID.
     */
    public function testFallbackScenarioIdIsDeterministic(): void
    {
        $input = [
            'type' => 'controller',
            'class' => '',
            'scenario' => 'frontend.controller.cart.add',
        ];

        $id1 = ScenarioSeedResolver::scenarioId($input);
        $id2 = ScenarioSeedResolver::scenarioId($input);

        $this->assertSame($id1, $id2, 'Same input must always produce same scenario_id');
    }

    /**
     * Bug 3 regression: ScenarioBundleGenerator must populate entry_point.class
     * from the entry_class field emitted by ExecutionPathExtractor.
     */
    public function testEntryPointClassIsPopulated(): void
    {
        $allData = $this->buildMinimalExtractorData([
            [
                'scenario' => 'frontend.controller.cart.add',
                'entry_point' => 'SecondSwing\\Checkout\\Controller\\Cart\\Add::execute',
                'entry_class' => 'SecondSwing\\Checkout\\Controller\\Cart\\Add',
                'type' => 'controller',
                'area' => 'frontend',
                'module' => 'SecondSwing_Checkout',
                'source_file' => 'app/code/SecondSwing/Checkout/Controller/Cart/Add.php',
                'resolved_class' => 'SecondSwing\\Checkout\\Controller\\Cart\\Add',
                'preferences_resolved' => [],
                'plugin_stack' => [],
                'plugin_depth' => 0,
                'before_plugins' => 0,
                'after_plugins' => 0,
                'around_plugins' => 0,
                'triggered_observers' => [],
                'observer_count' => 0,
            ],
        ]);

        $generator = new ScenarioBundleGenerator();
        $scenarios = $generator->generate($allData);

        $this->assertNotEmpty($scenarios, 'At least one scenario must be generated');

        $firstScenario = reset($scenarios);
        $this->assertNotEmpty(
            $firstScenario['entry_point']['class'],
            'entry_point.class must be populated from entry_class field'
        );
        $this->assertSame(
            'SecondSwing\\Checkout\\Controller\\Cart\\Add',
            $firstScenario['entry_point']['class']
        );
    }

    /**
     * Bug 3 regression: When entry_class is present, the fallback scenario_id
     * should include it, making the hash more distinctive.
     */
    public function testFallbackHashIncludesClassWhenPresent(): void
    {
        $withClass = ScenarioSeedResolver::scenarioId([
            'type' => 'controller',
            'class' => 'SecondSwing\\Checkout\\Controller\\Cart\\Add',
            'scenario' => 'frontend.controller.cart.add',
        ]);

        $withoutClass = ScenarioSeedResolver::scenarioId([
            'type' => 'controller',
            'class' => '',
            'scenario' => 'frontend.controller.cart.add',
        ]);

        $this->assertNotEquals(
            $withClass,
            $withoutClass,
            'Presence of class should change the scenario_id hash'
        );
    }

    /**
     * Bug 2 regression: Template override deviations with 0 templates must not be emitted.
     *
     * Creates a temp directory structure simulating a theme with a Magento_* dir
     * that contains no .phtml files, then verifies no zero-count deviation is produced.
     */
    public function testZeroTemplateOverridesNotEmitted(): void
    {
        $tmpDir = sys_get_temp_dir() . '/magecontext-deviation-test-' . uniqid();
        $designDir = $tmpDir . '/app/design/frontend/vendor/theme/Magento_Cms/web/css';
        mkdir($designDir, 0755, true);
        // Create a non-template file (CSS) so the Magento_Cms dir is discovered
        file_put_contents($designDir . '/styles.css', 'body {}');

        try {
            $extractor = new DeviationExtractor();
            $result = $extractor->extract($tmpDir, ['app/design']);

            $templateDeviations = array_filter(
                $result['deviations'],
                fn($d) => $d['type'] === 'template_override'
            );

            $this->assertEmpty(
                $templateDeviations,
                'No template_override deviations should be emitted when directory has no .phtml files'
            );
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /**
     * Bug 2 regression: Template override deviations with real .phtml files ARE emitted.
     */
    public function testRealTemplateOverridesAreEmitted(): void
    {
        $tmpDir = sys_get_temp_dir() . '/magecontext-deviation-test-' . uniqid();
        $moduleDir = $tmpDir . '/app/design/frontend/vendor/theme/Magento_Catalog';
        mkdir($moduleDir, 0755, true);
        file_put_contents($moduleDir . '/list.phtml', '<div>override</div>');

        try {
            $extractor = new DeviationExtractor();
            $result = $extractor->extract($tmpDir, ['app/design']);

            $templateDeviations = array_filter(
                $result['deviations'],
                fn($d) => $d['type'] === 'template_override'
            );

            $this->assertNotEmpty(
                $templateDeviations,
                'Template override with real .phtml files should produce a deviation'
            );

            $first = reset($templateDeviations);
            $this->assertGreaterThanOrEqual(1, $first['details']['total_templates']);
            $this->assertSame('Magento_Catalog', $first['details']['module']);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /**
     * Build minimal allExtractedData with execution paths for ScenarioBundleGenerator.
     */
    private function buildMinimalExtractorData(array $paths): array
    {
        return [
            'execution_paths' => ['paths' => $paths],
            'modules' => ['modules' => []],
            'modifiability' => ['modules' => []],
            'architectural_debt' => ['debt_items' => []],
            'layer_classification' => ['violations' => []],
            'performance' => ['indicators' => []],
            'hotspot_ranking' => ['rankings' => []],
            'route_map' => ['routes' => []],
            'cron_map' => ['cron_jobs' => []],
            'cli_commands' => ['commands' => []],
            'api_surface' => ['endpoints' => []],
            'reverse_index' => ['by_module' => []],
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

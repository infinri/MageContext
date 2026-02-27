<?php

declare(strict_types=1);

namespace MageContext\Target;

use MageContext\Extractor\ExtractorInterface;
use MageContext\Extractor\Magento\AllocationExtractor;
use MageContext\Extractor\Magento\ApiSurfaceExtractor;
use MageContext\Extractor\Magento\ArchitecturalDebtExtractor;
use MageContext\Extractor\Magento\DbSchemaExtractor;
use MageContext\Extractor\Magento\DependencyGraphExtractor;
use MageContext\Extractor\Magento\DeviationExtractor;
use MageContext\Extractor\Magento\ExecutionPathExtractor;
use MageContext\Extractor\Magento\LayerClassificationExtractor;
use MageContext\Extractor\Magento\DiPreferenceExtractor;
use MageContext\Extractor\Magento\HotspotRankingExtractor;
use MageContext\Extractor\Magento\LayoutExtractor;
use MageContext\Extractor\Magento\ModifiabilityExtractor;
use MageContext\Extractor\Magento\ModuleGraphExtractor;
use MageContext\Extractor\Magento\ObserverExtractor;
use MageContext\Extractor\Magento\PerformanceIndicatorExtractor;
use MageContext\Extractor\Magento\PluginExtractor;
use MageContext\Extractor\Magento\RoutesExtractor;
use MageContext\Extractor\Magento\CronMapExtractor;
use MageContext\Extractor\Magento\CliCommandExtractor;
use MageContext\Extractor\Magento\UiComponentExtractor;
use MageContext\Extractor\Magento\CallGraphExtractor;
use MageContext\Extractor\Magento\ServiceContractExtractor;
use MageContext\Extractor\Magento\RepositoryPatternExtractor;
use MageContext\Extractor\Magento\EntityRelationshipExtractor;
use MageContext\Extractor\Magento\PluginSeamTimingExtractor;
use MageContext\Extractor\Magento\SafeApiMatrixExtractor;
use MageContext\Extractor\Magento\DtoDataInterfaceExtractor;
use MageContext\Extractor\Magento\ImplementationPatternExtractor;

class MagentoTarget implements TargetInterface
{
    public function getName(): string
    {
        return 'magento';
    }

    public function getDescription(): string
    {
        return 'Magento 2 / Adobe Commerce';
    }

    public function getDefaultScopes(): array
    {
        return ['app/code', 'app/design'];
    }

    public function detect(string $repoPath): bool
    {
        // Check for Magento-specific markers
        $markers = [
            $repoPath . '/app/etc/env.php',
            $repoPath . '/app/etc/config.php',
            $repoPath . '/bin/magento',
        ];

        foreach ($markers as $marker) {
            if (is_file($marker)) {
                return true;
            }
        }

        // Check composer.json for magento framework dependency
        $composerPath = $repoPath . '/composer.json';
        if (is_file($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $require = array_merge(
                $composer['require'] ?? [],
                $composer['require-dev'] ?? []
            );

            foreach (array_keys($require) as $package) {
                if (str_starts_with($package, 'magento/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getExtractors(): array
    {
        return [
            new ModuleGraphExtractor(),
            new DependencyGraphExtractor(),
            new LayerClassificationExtractor(),
            new ExecutionPathExtractor(),
            new DiPreferenceExtractor(),
            new PluginExtractor(),
            new ObserverExtractor(),
            new LayoutExtractor(),
            new RoutesExtractor(),
            new CronMapExtractor(),
            new CliCommandExtractor(),
            new UiComponentExtractor(),
            new DbSchemaExtractor(),
            new ApiSurfaceExtractor(),
            new DeviationExtractor(),
            new ModifiabilityExtractor(),
            new PerformanceIndicatorExtractor(),
            new ArchitecturalDebtExtractor(),
            new HotspotRankingExtractor(),
            new AllocationExtractor(),
            new CallGraphExtractor(),
            new ServiceContractExtractor(),
            new RepositoryPatternExtractor(),
            new EntityRelationshipExtractor(),
            new PluginSeamTimingExtractor(),
            new SafeApiMatrixExtractor(),
            new DtoDataInterfaceExtractor(),
            new ImplementationPatternExtractor(),
        ];
    }

}

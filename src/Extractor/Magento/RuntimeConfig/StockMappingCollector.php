<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Collects stock infrastructure capability signals from repo files.
 *
 * Two tiers:
 *  - Static capability inference (repo-based): MSI services in DI, schema presence.
 *    Confidence: inferred_static. This tells you MSI is *capable*, not how it is *configured*.
 *  - Runtime mapping (requires DB): actual website→stock assignments live in
 *    inventory_stock_sales_channel and related tables. NOT extracted here.
 *
 * @see FW-M2-RT-006
 */
class StockMappingCollector
{
    public function collect(CollectorContext $ctx): array
    {
        $stockResolverPrefs = [];
        $legacyStockRegistryPrefs = [];
        $msiTables = [];

        foreach ($ctx->scopePaths() as $scopePath) {
            // Scan di.xml for StockResolver / StockRegistry preferences
            $diFinder = new Finder();
            $diFinder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($diFinder as $file) {
                $fileId = $ctx->fileId($file->getRealPath());
                $declaringModule = $ctx->resolveModuleFromFile($file->getRealPath());
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = IdentityResolver::normalizeFqcn((string) ($node['for'] ?? ''));
                    $type = IdentityResolver::normalizeFqcn((string) ($node['type'] ?? ''));

                    if (str_contains($for, 'StockResolverInterface') || str_contains($for, 'GetProductSalableQtyInterface')) {
                        $stockResolverPrefs[] = [
                            'interface' => $for,
                            'implementation' => $type,
                            'module' => $declaringModule,
                            'evidence' => [Evidence::fromXml($fileId, "DI preference for {$for}")->toArray()],
                        ];
                    }

                    if (str_contains($for, 'StockRegistryInterface') || str_contains($for, 'StockStatusRepositoryInterface')) {
                        $legacyStockRegistryPrefs[] = [
                            'interface' => $for,
                            'implementation' => $type,
                            'module' => $declaringModule,
                            'evidence' => [Evidence::fromXml($fileId, "Legacy stock DI preference for {$for}")->toArray()],
                        ];
                    }
                }
            }

            // Scan db_schema.xml for MSI-related tables
            $schemaFinder = new Finder();
            $schemaFinder->files()->in($scopePath)->name('db_schema.xml')->sortByName();

            foreach ($schemaFinder as $file) {
                $fileId = $ctx->fileId($file->getRealPath());
                $declaringModule = $ctx->resolveModuleFromFile($file->getRealPath());
                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                foreach ($xml->table ?? [] as $tableNode) {
                    $tableName = (string) ($tableNode['name'] ?? '');
                    if ($tableName !== '' && (
                        str_starts_with($tableName, 'inventory_') ||
                        str_starts_with($tableName, 'cataloginventory_stock') ||
                        str_contains($tableName, 'source_item') ||
                        preg_match('/(?:^|_)stock(?:_|$)/', $tableName)
                    ) && !str_starts_with($tableName, 'adobe_stock')) {
                        $msiTables[] = [
                            'table' => $tableName,
                            'module' => $declaringModule,
                            'evidence' => [Evidence::fromXml($fileId, "MSI-related table '{$tableName}'")->toArray()],
                        ];
                    }
                }
            }
        }

        usort($stockResolverPrefs, fn($a, $b) => strcmp($a['interface'], $b['interface']));
        usort($legacyStockRegistryPrefs, fn($a, $b) => strcmp($a['interface'], $b['interface']));
        usort($msiTables, fn($a, $b) => strcmp($a['table'], $b['table']));

        return [
            '_meta' => [
                'confidence' => 'inferred_static',
                'source_type' => 'repo_file',
                'sources' => ['di.xml', 'db_schema.xml'],
                'runtime_required' => true,
                'limitations' => 'Detects MSI capability (DI resolver active, schema present). '
                    . 'Actual website→stock assignments live in inventory_stock_sales_channel table and require database access.',
            ],
            'actual_mapping_known' => false,
            'stock_resolver_preferences' => $stockResolverPrefs,
            'legacy_stock_registry_preferences' => $legacyStockRegistryPrefs,
            'msi_tables' => $msiTables,
        ];
    }
}

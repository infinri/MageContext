<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;

/**
 * Collects MSI (Multi-Source Inventory) module enabled/disabled status
 * from app/etc/config.php.
 *
 * Confidence: authoritative_static — config.php is the authoritative module registry.
 * Limitation: module presence ≠ MSI fully configured; actual stock/source
 * assignments live in the database.
 *
 * @see FW-M2-RT-001, FW-M2-RT-006
 */
class MsiStatusCollector
{
    public function collect(CollectorContext $ctx): array
    {
        $configPath = $ctx->repoPath() . '/app/etc/config.php';
        $modules = [];
        $msiActive = false;
        $configFound = false;

        if (is_file($configPath)) {
            $configFound = true;
            $config = @include $configPath;
            if (is_array($config) && isset($config['modules'])) {
                foreach ($config['modules'] as $moduleName => $status) {
                    if (str_starts_with($moduleName, 'Magento_Inventory')) {
                        $enabled = (bool) $status;
                        $modules[] = [
                            'module' => $moduleName,
                            'enabled' => $enabled,
                            'evidence' => [
                                Evidence::fromXml(
                                    'app/etc/config.php',
                                    "MSI module {$moduleName} " . ($enabled ? 'enabled' : 'disabled')
                                )->toArray(),
                            ],
                        ];
                    }
                }
            } else {
                $ctx->warnGeneral('Could not parse app/etc/config.php for MSI module status');
            }
        }

        foreach ($modules as $m) {
            if ($m['module'] === 'Magento_Inventory' && $m['enabled']) {
                $msiActive = true;
                break;
            }
        }

        usort($modules, fn($a, $b) => strcmp($a['module'], $b['module']));

        return [
            '_meta' => [
                'confidence' => 'authoritative_static',
                'source_type' => 'repo_file',
                'sources' => ['app/etc/config.php'],
                'runtime_required' => false,
                'limitations' => 'Module presence does not guarantee MSI is fully configured; stock/source assignments require database access.',
            ],
            'config_php_found' => $configFound,
            'msi_active' => $msiActive,
            'modules' => $modules,
        ];
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Identity\Evidence;

/**
 * Collects DB connection hints from env.php and declared table engine
 * intentions from db_schema.xml.
 *
 * Two tiers:
 *  - Configured connection hints (env.php): engine, isolation_level if present.
 *    These are *configured* values; actual server-level or session-level settings
 *    may differ.
 *  - Declared engine intentions (db_schema.xml): what Magento *intends* the table
 *    engine to be. In real installs, tables may drift from declarations.
 *
 * Output uses "declared_engine_hints" (not "engine_distribution") to avoid
 * implying authoritative knowledge of live database state.
 */
class DbConfigCollector
{
    public function collect(CollectorContext $ctx): array
    {
        $connections = [];
        $declaredTableEngines = [];
        $declaredEngineHints = [];

        // Parse app/etc/env.php for DB connection config
        $envPath = $ctx->repoPath() . '/app/etc/env.php';
        $envFound = false;
        if (is_file($envPath)) {
            $envFound = true;
            $env = @include $envPath;
            if (is_array($env) && isset($env['db']['connection'])) {
                foreach ($env['db']['connection'] as $connName => $connConfig) {
                    $connections[] = [
                        'connection' => $connName,
                        'configured_engine' => $connConfig['engine'] ?? 'innodb',
                        'active' => (bool) ($connConfig['active'] ?? true),
                        'configured_isolation_level' => $connConfig['isolation_level'] ?? null,
                        'evidence' => [
                            Evidence::fromXml(
                                'app/etc/env.php',
                                "DB connection '{$connName}'"
                            )->toArray(),
                        ],
                    ];
                }
            }
        }

        // Scan db_schema.xml for declared table engine attributes
        foreach ($ctx->scopePaths() as $scopePath) {
            $ctx->findAndParseXml($scopePath, 'db_schema.xml', function ($xml, $fileId, $module) use (&$declaredTableEngines) {
                foreach ($xml->table ?? [] as $tableNode) {
                    $tableName = (string) ($tableNode['name'] ?? '');
                    $engine = (string) ($tableNode['engine'] ?? '');
                    $resource = (string) ($tableNode['resource'] ?? 'default');
                    if ($tableName !== '') {
                        $declaredTableEngines[] = [
                            'table' => $tableName,
                            'declared_engine' => $engine !== '' ? $engine : 'innodb',
                            'resource' => $resource,
                            'module' => $module,
                            'evidence' => [Evidence::fromXml($fileId, "Table '{$tableName}' declared engine='{$engine}'")->toArray()],
                        ];
                    }
                }
            });
        }

        // Compute declared engine hints (not live distribution)
        foreach ($declaredTableEngines as $te) {
            $eng = strtolower($te['declared_engine']);
            $declaredEngineHints[$eng] = ($declaredEngineHints[$eng] ?? 0) + 1;
        }
        ksort($declaredEngineHints);

        usort($connections, fn($a, $b) => strcmp($a['connection'], $b['connection']));
        usort($declaredTableEngines, fn($a, $b) => strcmp($a['table'], $b['table']));

        return [
            '_meta' => [
                'confidence' => 'declared_config',
                'source_type' => 'repo_file',
                'sources' => ['app/etc/env.php', 'db_schema.xml'],
                'runtime_required' => true,
                'limitations' => 'Does not inspect live database. env.php values are configured hints; '
                    . 'actual isolation level may differ at server or session level. '
                    . 'Declared table engines are intentions from db_schema.xml; does not detect per-table engine drift in production.',
            ],
            'env_php_found' => $envFound,
            'configured_connections' => $connections,
            'declared_table_engines' => $declaredTableEngines,
            'declared_engine_hints' => $declaredEngineHints,
        ];
    }
}

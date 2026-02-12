<?php

declare(strict_types=1);

namespace MageContext\Output;

/**
 * B+.5: Single source of truth for scenario seeds.
 *
 * Collects all entrypoints from extractor output and produces
 * deduplicated scenario seeds with stable, deterministic IDs.
 *
 * Frozen canonical entry shapes:
 *   route: {type:"route", area, route_id}
 *   cron:  {type:"cron", group, cron_id}
 *   cli:   {type:"cli", command_name}
 *   api:   {type:"api", method, path}
 *
 * No re-discovery in ScenarioBundleGenerator or ExecutionPathExtractor.
 */
class ScenarioSeedResolver
{
    /** @var array<string, array{scenario_id: string, canonical_entry: array}> keyed by scenario_id */
    private array $seeds = [];

    /**
     * Resolve scenario seeds from all extractor output.
     *
     * @param array<string, array> $allExtractedData All extractor results keyed by name
     * @return array<array{scenario_id: string, canonical_entry: array}>
     */
    public function resolve(array $allExtractedData): array
    {
        $this->seeds = [];

        // Routes
        foreach ($allExtractedData['route_map']['routes'] ?? [] as $route) {
            $this->addSeed([
                'type' => 'route',
                'area' => $route['area'] ?? '',
                'route_id' => $route['route_id'] ?? '',
            ]);
        }

        // Cron jobs
        foreach ($allExtractedData['cron_map']['cron_jobs'] ?? [] as $cron) {
            $this->addSeed([
                'type' => 'cron',
                'group' => $cron['group'] ?? 'default',
                'cron_id' => $cron['cron_id'] ?? '',
            ]);
        }

        // CLI commands
        foreach ($allExtractedData['cli_commands']['commands'] ?? [] as $cmd) {
            $this->addSeed([
                'type' => 'cli',
                'command_name' => $cmd['command_name'] ?? '',
            ]);
        }

        // API endpoints
        foreach ($allExtractedData['api_surface']['endpoints'] ?? [] as $endpoint) {
            $this->addSeed([
                'type' => 'api',
                'method' => $endpoint['method'] ?? '',
                'path' => $endpoint['path'] ?? '',
            ]);
        }

        return array_values($this->seeds);
    }

    /**
     * Add a scenario seed, deduplicating by scenario_id.
     */
    private function addSeed(array $canonicalEntry): void
    {
        $id = self::scenarioId($canonicalEntry);
        if (!isset($this->seeds[$id])) {
            $this->seeds[$id] = [
                'scenario_id' => $id,
                'canonical_entry' => $canonicalEntry,
            ];
        }
    }

    /**
     * Generate a deterministic scenario ID from a canonical entry.
     *
     * @param array $canonicalEntry Frozen-shape entry
     * @return string sha1 hash
     */
    public static function scenarioId(array $canonicalEntry): string
    {
        return sha1(self::canonicalJson($canonicalEntry));
    }

    /**
     * Produce canonical JSON for deterministic hashing.
     *
     * Recursive ksort (not just top-level) + JSON_UNESCAPED_SLASHES.
     * Future-proofs against nested canonical entry growth.
     */
    public static function canonicalJson(array $data): string
    {
        self::recursiveKsort($data);
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Recursively ksort all associative arrays in the structure.
     */
    private static function recursiveKsort(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value) && self::isAssoc($value)) {
                self::recursiveKsort($value);
            }
        }
    }

    /**
     * Check if an array is associative.
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Resolver;

class ContextResolver
{
    private string $contextDir;
    private array $index = [];

    public function __construct(string $contextDir)
    {
        $this->contextDir = rtrim($contextDir, '/');
    }

    /**
     * Load all compiled JSON files into an in-memory index.
     */
    public function load(): void
    {
        $manifestPath = $this->contextDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException(
                "No compiled context found at {$this->contextDir}. Run 'compile' first."
            );
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        foreach ($manifest['files'] ?? [] as $relativePath) {
            $fullPath = $this->contextDir . '/' . $relativePath;
            if (!is_file($fullPath)) {
                continue;
            }

            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            if ($ext === 'json') {
                // Use filename as key (strips view directory prefix)
                $key = pathinfo($relativePath, PATHINFO_FILENAME);
                $this->index[$key] = json_decode(file_get_contents($fullPath), true);
            } elseif ($ext === 'md') {
                $key = pathinfo($relativePath, PATHINFO_FILENAME) . '_md';
                $this->index[$key] = file_get_contents($fullPath);
            }
        }
    }

    /**
     * Get the full loaded index (for external consumers).
     *
     * @return array<string, mixed>
     */
    public function getIndex(): array
    {
        return $this->index;
    }

    /**
     * Resolve relevant context given keywords extracted from an issue or stack trace.
     *
     * @param array<string> $keywords Class names, module names, file paths, method names, event names
     * @return array<string, mixed> Relevant context slices keyed by section
     */
    public function resolve(array $keywords): array
    {
        $result = [];

        $result['modules'] = $this->findRelevantModules($keywords);
        $result['di_preferences'] = $this->findRelevantPreferences($keywords);
        $result['plugins'] = $this->findRelevantPlugins($keywords);
        $result['observers'] = $this->findRelevantObservers($keywords);
        $result['layout'] = $this->findRelevantLayout($keywords);
        $result['route_map'] = $this->findRelevantRoutes($keywords);
        $result['db_schema'] = $this->findRelevantDbSchema($keywords);
        $result['api'] = $this->findRelevantApi($keywords);
        $result['deviations'] = $this->findRelevantDeviations($keywords);
        $result['execution_paths'] = $this->findRelevantExecutionPaths($keywords);
        $result['layer_violations'] = $this->findRelevantLayerViolations($keywords);
        $result['architectural_debt'] = $this->findRelevantDebt($keywords);
        $result['modifiability'] = $this->findRelevantModifiability($keywords);

        // Filter out empty sections
        return array_filter($result, fn($v) => !empty($v));
    }

    /**
     * Extract searchable keywords from an issue description and/or stack trace.
     *
     * @return array<string> Normalized keywords
     */
    public function extractKeywords(string $issueText, string $stackTrace = ''): array
    {
        $combined = $issueText . "\n" . $stackTrace;
        $keywords = [];

        // Extract fully qualified class names (Vendor\Module\Path\Class)
        if (preg_match_all('/(?:[A-Z][a-z]+\\\\){2,}[A-Za-z\\\\]+/', $combined, $matches)) {
            foreach ($matches[0] as $match) {
                $keywords[] = trim($match, '\\');
            }
        }

        // Extract Magento module names (Vendor_Module pattern)
        if (preg_match_all('/[A-Z][a-z]+_[A-Z][A-Za-z]+/', $combined, $matches)) {
            foreach ($matches[0] as $match) {
                $keywords[] = $match;
            }
        }

        // Extract file paths (app/code/... or app/design/...)
        if (preg_match_all('#app/(?:code|design)/[\w/.-]+#', $combined, $matches)) {
            foreach ($matches[0] as $match) {
                $keywords[] = $match;
            }
        }

        // Extract method names from stack traces (->methodName( or ::methodName()
        if (preg_match_all('/(?:->|::)(\w+)\s*\(/', $combined, $matches)) {
            foreach ($matches[1] as $match) {
                if (strlen($match) > 3 && $match !== 'execute') {
                    $keywords[] = $match;
                }
            }
        }

        // Extract event names (snake_case with underscores, at least 2 parts)
        if (preg_match_all('/\b([a-z][a-z0-9]*(?:_[a-z0-9]+){2,})\b/', $combined, $matches)) {
            foreach ($matches[1] as $match) {
                $keywords[] = $match;
            }
        }

        // Extract Magento area names
        $areas = ['checkout', 'catalog', 'sales', 'customer', 'payment', 'shipping', 'quote', 'cart', 'order', 'invoice', 'creditmemo'];
        foreach ($areas as $area) {
            if (stripos($combined, $area) !== false) {
                $keywords[] = $area;
            }
        }

        // Deduplicate and normalize
        $keywords = array_unique(array_map('trim', $keywords));
        return array_values(array_filter($keywords, fn($k) => strlen($k) >= 3));
    }

    private function findRelevantModules(array $keywords): array
    {
        $modules = $this->index['modules']['modules'] ?? [];
        return $this->filterByKeywords($modules, $keywords, ['name', 'path']);
    }

    private function findRelevantPreferences(array $keywords): array
    {
        $resolutions = $this->index['di_resolution_map']['resolutions'] ?? [];
        return $this->filterByKeywords($resolutions, $keywords, ['interface', 'di_target_id']);
    }

    private function findRelevantPlugins(array $keywords): array
    {
        $plugins = $this->index['plugin_chains']['plugins'] ?? [];
        $matched = $this->filterByKeywords($plugins, $keywords, ['target_class', 'plugin_class', 'plugin_name', 'source_file']);

        // Also include chain view for matched target classes
        $classChains = $this->index['plugin_chains']['class_chains'] ?? [];
        $methodChains = $this->index['plugin_chains']['method_chains'] ?? [];
        $chainResult = [];
        foreach ($matched as $plugin) {
            $target = $plugin['target_class'] ?? '';
            if (isset($classChains[$target]) && !isset($chainResult[$target])) {
                $chainResult[$target] = $classChains[$target];
            }
        }

        return [
            'matched_plugins' => $matched,
            'relevant_chains' => $chainResult,
        ];
    }

    private function findRelevantObservers(array $keywords): array
    {
        $observers = $this->index['event_graph']['observers'] ?? [];
        return $this->filterByKeywords($observers, $keywords, ['event_name', 'observer_class', 'observer_name', 'source_file']);
    }

    private function findRelevantLayout(array $keywords): array
    {
        $handles = $this->index['layout_handles']['handles'] ?? [];
        return $this->filterByKeywords($handles, $keywords, ['handle', 'name', 'class', 'template', 'source_file']);
    }

    private function findRelevantRoutes(array $keywords): array
    {
        $routes = $this->index['route_map']['routes'] ?? [];
        return $this->filterByKeywords($routes, $keywords, ['route_id', 'front_name', 'source_file']);
    }

    private function findRelevantDbSchema(array $keywords): array
    {
        $tables = $this->index['db_schema_patches']['tables'] ?? [];
        $patches = $this->index['db_schema_patches']['patches'] ?? [];

        return [
            'tables' => $this->filterByKeywords($tables, $keywords, ['name', 'comment', 'source_file']),
            'patches' => $this->filterByKeywords($patches, $keywords, ['class', 'source_file']),
        ];
    }

    private function findRelevantApi(array $keywords): array
    {
        $rest = $this->index['api_surface']['rest_endpoints'] ?? [];
        $graphql = $this->index['api_surface']['graphql_types'] ?? [];

        return [
            'rest' => $this->filterByKeywords($rest, $keywords, ['url', 'service_class', 'service_method', 'source_file']),
            'graphql' => $this->filterByKeywords($graphql, $keywords, ['name', 'resolver_class', 'source_file']),
        ];
    }

    private function findRelevantDeviations(array $keywords): array
    {
        $deviations = $this->index['custom_deviations']['deviations'] ?? [];
        return $this->filterByKeywords($deviations, $keywords, ['message', 'source_file', 'type']);
    }

    private function findRelevantExecutionPaths(array $keywords): array
    {
        $paths = $this->index['execution_paths']['paths'] ?? [];
        return $this->filterByKeywords($paths, $keywords, ['entry_class', 'module', 'scenario']);
    }

    private function findRelevantLayerViolations(array $keywords): array
    {
        $violations = $this->index['layer_classification']['violations'] ?? [];
        return $this->filterByKeywords($violations, $keywords, ['from', 'to', 'module']);
    }

    private function findRelevantDebt(array $keywords): array
    {
        $items = $this->index['architectural_debt']['debt_items'] ?? [];
        return $this->filterByKeywords($items, $keywords, ['description', 'modules']);
    }

    private function findRelevantModifiability(array $keywords): array
    {
        $modules = $this->index['modifiability']['modules'] ?? [];
        return $this->filterByKeywords($modules, $keywords, ['module']);
    }

    /**
     * Filter an array of records by checking if any keyword matches any of the specified fields.
     *
     * @param array<array> $records
     * @param array<string> $keywords
     * @param array<string> $fields Fields to search within each record
     * @return array<array> Matching records
     */
    private function filterByKeywords(array $records, array $keywords, array $fields): array
    {
        $matched = [];

        foreach ($records as $record) {
            foreach ($fields as $field) {
                $value = $record[$field] ?? '';
                if (!is_string($value)) {
                    continue;
                }

                foreach ($keywords as $keyword) {
                    if (stripos($value, $keyword) !== false) {
                        $matched[] = $record;
                        continue 3;
                    }
                }
            }
        }

        return $matched;
    }
}

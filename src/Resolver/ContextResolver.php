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
     * Load all compiled JSON and Markdown files into an in-memory index.
     */
    public function load(): void
    {
        $this->index = BundleLoader::load($this->contextDir, true);
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
        $result['call_graph'] = $this->findRelevantCallGraph($keywords);
        $result['service_contracts'] = $this->findRelevantServiceContracts($keywords);
        $result['repository_patterns'] = $this->findRelevantRepositoryPatterns($keywords);
        $result['entity_relationships'] = $this->findRelevantEntityRelationships($keywords);
        $result['plugin_seam_timing'] = $this->findRelevantPluginSeams($keywords);
        $result['safe_api_matrix'] = $this->findRelevantSafeApiMatrix($keywords);
        $result['dto_data_interfaces'] = $this->findRelevantDtoInterfaces($keywords);
        $result['implementation_patterns'] = $this->findRelevantImplementationPatterns($keywords);

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
        return BundleLoader::filterRecords($modules, $keywords, ['name', 'path']);
    }

    private function findRelevantPreferences(array $keywords): array
    {
        $resolutions = $this->index['di_resolution_map']['resolutions'] ?? [];
        return BundleLoader::filterRecords($resolutions, $keywords, ['interface', 'di_target_id']);
    }

    private function findRelevantPlugins(array $keywords): array
    {
        $plugins = $this->index['plugin_chains']['plugins'] ?? [];
        $matched = BundleLoader::filterRecords($plugins, $keywords, ['target_class', 'plugin_class', 'plugin_name', 'source_file']);

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
        return BundleLoader::filterRecords($observers, $keywords, ['event_name', 'observer_class', 'observer_name', 'source_file']);
    }

    private function findRelevantLayout(array $keywords): array
    {
        $handles = $this->index['layout_handles']['handles'] ?? [];
        return BundleLoader::filterRecords($handles, $keywords, ['handle', 'name', 'class', 'template', 'source_file']);
    }

    private function findRelevantRoutes(array $keywords): array
    {
        $routes = $this->index['route_map']['routes'] ?? [];
        return BundleLoader::filterRecords($routes, $keywords, ['route_id', 'front_name', 'source_file']);
    }

    private function findRelevantDbSchema(array $keywords): array
    {
        $tables = $this->index['db_schema_patches']['tables'] ?? [];
        $patches = $this->index['db_schema_patches']['patches'] ?? [];

        return [
            'tables' => BundleLoader::filterRecords($tables, $keywords, ['name', 'comment', 'source_file']),
            'patches' => BundleLoader::filterRecords($patches, $keywords, ['class', 'source_file']),
        ];
    }

    private function findRelevantApi(array $keywords): array
    {
        $rest = $this->index['api_surface']['rest_endpoints'] ?? [];
        $graphql = $this->index['api_surface']['graphql_types'] ?? [];

        return [
            'rest' => BundleLoader::filterRecords($rest, $keywords, ['url', 'service_class', 'service_method', 'source_file']),
            'graphql' => BundleLoader::filterRecords($graphql, $keywords, ['name', 'resolver_class', 'source_file']),
        ];
    }

    private function findRelevantDeviations(array $keywords): array
    {
        $deviations = $this->index['custom_deviations']['deviations'] ?? [];
        return BundleLoader::filterRecords($deviations, $keywords, ['message', 'source_file', 'type']);
    }

    private function findRelevantExecutionPaths(array $keywords): array
    {
        $paths = $this->index['execution_paths']['paths'] ?? [];
        return BundleLoader::filterRecords($paths, $keywords, ['entry_class', 'module', 'scenario']);
    }

    private function findRelevantLayerViolations(array $keywords): array
    {
        $violations = $this->index['layer_classification']['violations'] ?? [];
        return BundleLoader::filterRecords($violations, $keywords, ['from', 'to', 'module']);
    }

    private function findRelevantDebt(array $keywords): array
    {
        $items = $this->index['architectural_debt']['debt_items'] ?? [];
        return BundleLoader::filterRecords($items, $keywords, ['description', 'modules']);
    }

    private function findRelevantModifiability(array $keywords): array
    {
        $modules = $this->index['modifiability']['modules'] ?? [];
        return BundleLoader::filterRecords($modules, $keywords, ['module']);
    }

    private function findRelevantCallGraph(array $keywords): array
    {
        $chains = $this->index['call_graph']['delegation_chains'] ?? [];
        return BundleLoader::filterRecords($chains, $keywords, ['service_interface', 'final_concrete', 'module', 'url']);
    }

    private function findRelevantServiceContracts(array $keywords): array
    {
        $contracts = $this->index['service_contracts']['contracts'] ?? [];
        return BundleLoader::filterRecords($contracts, $keywords, ['interface', 'module', 'source_file']);
    }

    private function findRelevantRepositoryPatterns(array $keywords): array
    {
        $repos = $this->index['repository_patterns']['repositories'] ?? [];
        return BundleLoader::filterRecords($repos, $keywords, ['interface', 'entity_name', 'module', 'source_file']);
    }

    private function findRelevantEntityRelationships(array $keywords): array
    {
        $entities = $this->index['entity_relationships']['entities'] ?? [];
        $relationships = $this->index['entity_relationships']['relationships'] ?? [];

        return [
            'entities' => BundleLoader::filterRecords($entities, $keywords, ['entity_class', 'table', 'module']),
            'relationships' => BundleLoader::filterRecords($relationships, $keywords, ['from_table', 'to_table', 'from_entity', 'to_entity']),
        ];
    }

    private function findRelevantPluginSeams(array $keywords): array
    {
        $seams = $this->index['plugin_seam_timing']['seams'] ?? [];
        return BundleLoader::filterRecords($seams, $keywords, ['target_class', 'target_method', 'seam_id']);
    }

    private function findRelevantSafeApiMatrix(array $keywords): array
    {
        $matrix = $this->index['safe_api_matrix']['class_matrix'] ?? [];
        return BundleLoader::filterRecords($matrix, $keywords, ['class', 'module', 'source_file']);
    }

    private function findRelevantDtoInterfaces(array $keywords): array
    {
        $dtos = $this->index['dto_data_interfaces']['data_interfaces'] ?? [];
        return BundleLoader::filterRecords($dtos, $keywords, ['interface', 'module', 'source_file']);
    }

    private function findRelevantImplementationPatterns(array $keywords): array
    {
        $impls = $this->index['implementation_patterns']['implementations'] ?? [];
        return BundleLoader::filterRecords($impls, $keywords, ['interface', 'concrete_class', 'module', 'source_file']);
    }
}

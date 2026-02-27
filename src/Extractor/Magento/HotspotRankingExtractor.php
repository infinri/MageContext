<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Hotspot ranking with evidence.
 */
class HotspotRankingExtractor extends AbstractExtractor
{
    /** @var array<string, int> */
    private array $lastModuleChurn = [];
    public function getName(): string
    {
        return 'hotspot_ranking';
    }

    public function getDescription(): string
    {
        return 'Ranks modules by combined git churn and dependency graph centrality';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // 1. Get per-module churn counts
        $moduleChurn = $this->computeModuleChurn($repoPath, $scopes);

        // 2. Get per-module weighted centrality from dependency graph
        $moduleCentrality = $this->computeModuleCentrality($repoPath, $scopes);

        // 3. Merge all known modules
        $allModules = array_unique(array_merge(
            array_keys($moduleChurn),
            array_keys($moduleCentrality)
        ));

        // 4. Collect raw values for percentile normalization
        $churnValues = [];
        $centralityValues = [];
        foreach ($allModules as $module) {
            if ($module === 'unknown') {
                continue;
            }
            $churnValues[$module] = (float) ($moduleChurn[$module] ?? 0);
            $centralityValues[$module] = (float) ($moduleCentrality[$module] ?? 0);
        }

        $allChurnValues = array_values($churnValues);
        $allCentralityValues = array_values($centralityValues);

        // 5. Build rankings with percentile_leq normalization + dampening
        $rankings = [];
        foreach ($allModules as $module) {
            if ($module === 'unknown') {
                continue;
            }

            $churn = $churnValues[$module] ?? 0.0;
            $centrality = $centralityValues[$module] ?? 0.0;

            $normalizedChurn = $this->percentileLeq($churn, $allChurnValues);
            $normalizedCentrality = $this->percentileLeq($centrality, $allCentralityValues);

            // Composite score: 60% churn + 40% centrality (frozen formula)
            $rawScore = 0.6 * $normalizedChurn + 0.4 * $normalizedCentrality;

            $rankings[] = array_merge([
                'module' => $module,
                'churn_count' => (int) $churn,
                'centrality' => round($centrality, 3),
                'normalized_churn' => $normalizedChurn,
                'normalized_centrality' => $normalizedCentrality,
                'evidence' => [Evidence::fromInference("churn={$churn} centrality=" . round($centrality, 3))->toArray()],
            ], $this->dampenScore($rawScore));
        }

        // Sort by final_score descending
        usort($rankings, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        $highRisk = array_filter($rankings, fn($r) => $r['final_score'] >= 0.5);

        return array_merge([
            'rankings' => $rankings,
            'summary' => [
                'total_modules_ranked' => count($rankings),
                'high_risk_hotspots' => count($highRisk),
                'normalization_method' => 'percentile_leq',
                'hotspot_formula' => '0.6 × percentile(churn) + 0.4 × percentile(centrality)',
            ],
        ], $this->integrityMeta());
    }

    /**
     * Compute total git churn per module by summing file-level churn.
     *
     * @return array<string, int> module => total churn count
     */
    private function computeModuleChurn(string $repoPath, array $scopes): array
    {
        if (!is_dir($repoPath . '/.git')) {
            return [];
        }

        $windowDays = $this->context !== null ? $this->config()->getChurnWindowDays() : 365;
        $cache = $this->context?->getChurnCache();

        // Try cache first
        $cached = $cache?->read($windowDays, $scopes);
        if ($cached !== null && !empty($cached['module_churn'])) {
            return $cached['module_churn'];
        }

        $this->lastModuleChurn = [];

        foreach ($scopes as $scope) {
            $scopePath = trim($scope, '/');
            $sinceArg = "{$windowDays} days ago";

            $process = new Process(
                ['git', 'log', '--name-only', '--pretty=format:', "--since={$sinceArg}", '--', $scopePath],
                $repoPath
            );
            $process->setTimeout(60);

            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }

            if (!$process->isSuccessful()) {
                continue;
            }

            $fileCounts = [];
            foreach (explode("\n", $process->getOutput()) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $fileCounts[$line] = ($fileCounts[$line] ?? 0) + 1;
            }

            foreach ($fileCounts as $file => $count) {
                $absPath = $repoPath . '/' . $file;
                $module = is_file($absPath) ? $this->resolveModuleFromFile($absPath) : $this->resolveModule(
                    // Fallback: extract module from path pattern app/code/Vendor/Module/...
                    preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $file, $m) ? $m[1] . '\\' . $m[2] : ''
                );
                if ($module !== 'unknown') {
                    $moduleChurn[$module] = ($moduleChurn[$module] ?? 0) + $count;
                }
            }
        }

        $this->lastModuleChurn = $moduleChurn;
        return $moduleChurn;
    }

    /**
     * Get last computed module churn data (for cache writing).
     *
     * @return array<string, int>
     */
    public function getModuleChurn(): array
    {
        return $this->lastModuleChurn;
    }

    /**
     * Compute weighted centrality per module from dependency graph.
     * Uses edge weights from config (B+.6). Only centrality_edge_types
     * participate — php_symbol_use is excluded (noisy).
     *
     * @return array<string, float> module => weighted degree
     */
    private function computeModuleCentrality(string $repoPath, array $scopes): array
    {
        $graph = $this->scanDependencyEdges($repoPath, $scopes)['edges'];

        // Get edge weights and allowed types from config
        $centralityTypes = [];
        $edgeWeightMap = [];
        if ($this->context !== null) {
            $centralityTypes = $this->config()->getCentralityEdgeTypes();
            $edgeWeightMap = $this->config()->getEdgeWeights();
        }

        $centrality = [];

        foreach ($graph as $edge) {
            $type = $edge['type'];

            // Only count centrality_edge_types
            if (!empty($centralityTypes) && !in_array($type, $centralityTypes, true)) {
                continue;
            }

            $weight = $edgeWeightMap[$type] ?? 1.0;
            $from = $edge['from'];
            $to = $edge['to'];

            $centrality[$from] = ($centrality[$from] ?? 0.0) + $weight;
            $centrality[$to] = ($centrality[$to] ?? 0.0) + $weight;
        }

        return $centrality;
    }

}

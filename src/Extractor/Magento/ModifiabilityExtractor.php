<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Per-module modifiability risk scoring with evidence.
 */
class ModifiabilityExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'modifiability';
    }

    public function getDescription(): string
    {
        return 'Computes per-module modifiability risk score from coupling, churn, plugin density, and deviations';
    }

    public function getOutputView(): string
    {
        return 'quality_metrics';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        // Collect raw signals per module
        $moduleSignals = [];

        // 1. Coupling: count dependencies per module from di.xml
        $this->collectCouplingSignals($repoPath, $scopes, $moduleSignals);

        // 2. Plugin density: count plugins per module
        $this->collectPluginSignals($repoPath, $scopes, $moduleSignals);

        // 3. Preference overrides per module
        $this->collectPreferenceSignals($repoPath, $scopes, $moduleSignals);

        // 4. Git churn per module
        $this->collectChurnSignals($repoPath, $scopes, $moduleSignals);

        // 5. Deviation count per module
        $this->collectDeviationSignals($repoPath, $scopes, $moduleSignals);

        // 6. File count per module (size signal)
        $this->collectSizeSignals($repoPath, $scopes, $moduleSignals);

        // Compute composite modifiability risk score
        $metrics = $this->computeModifiabilityScores($moduleSignals);

        // Sort by risk score descending
        usort($metrics, fn(array $a, array $b) => $b['modifiability_risk_score'] <=> $a['modifiability_risk_score']);

        $highRisk = array_filter($metrics, fn($m) => $m['modifiability_risk_score'] >= 0.7);

        return [
            'modules' => $metrics,
            'summary' => [
                'total_modules_scored' => count($metrics),
                'high_risk_modules' => count($highRisk),
                'avg_risk_score' => $this->avg(array_column($metrics, 'modifiability_risk_score')),
                'max_risk_score' => !empty($metrics) ? $metrics[0]['modifiability_risk_score'] : 0,
            ],
        ];
    }

    private function collectCouplingSignals(string $repoPath, array $scopes, array &$signals): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $module = $this->moduleIdFromPath(
                    str_replace($repoPath . '/', '', $file->getRealPath())
                );

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                // Count type configurations as coupling indicator
                $typeCount = count($xml->xpath('//type') ?: []);
                $this->addSignal($signals, $module, 'coupling_refs', $typeCount);
            }
        }
    }

    private function collectPluginSignals(string $repoPath, array $scopes, array &$signals): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $module = $this->moduleIdFromPath(
                    str_replace($repoPath . '/', '', $file->getRealPath())
                );

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                $pluginCount = 0;
                foreach ($xml->xpath('//type') ?: [] as $typeNode) {
                    foreach ($typeNode->plugin ?? [] as $pluginNode) {
                        $disabled = strtolower((string) ($pluginNode['disabled'] ?? 'false')) === 'true';
                        if (!$disabled) {
                            $pluginCount++;
                        }
                    }
                }

                $this->addSignal($signals, $module, 'plugin_count', $pluginCount);
            }
        }
    }

    private function collectPreferenceSignals(string $repoPath, array $scopes, array &$signals): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $module = $this->moduleIdFromPath(
                    str_replace($repoPath . '/', '', $file->getRealPath())
                );

                $xml = @simplexml_load_file($file->getRealPath());
                if ($xml === false) {
                    continue;
                }

                $prefCount = count($xml->xpath('//preference') ?: []);
                $coreOverrides = 0;
                foreach ($xml->xpath('//preference') ?: [] as $node) {
                    $for = (string) ($node['for'] ?? '');
                    if (str_starts_with($for, 'Magento\\')) {
                        $coreOverrides++;
                    }
                }

                $this->addSignal($signals, $module, 'preference_count', $prefCount);
                $this->addSignal($signals, $module, 'core_override_count', $coreOverrides);
            }
        }
    }

    private function collectChurnSignals(string $repoPath, array $scopes, array &$signals): void
    {
        // Respect churn config — skip if disabled
        if ($this->context !== null && !$this->config()->isChurnEnabled()) {
            return;
        }

        if (!is_dir($repoPath . '/.git')) {
            return;
        }

        $windowDays = $this->context !== null ? $this->config()->getChurnWindowDays() : 365;
        $cache = $this->context?->getChurnCache();

        // Try churn cache first (shared with GitChurnExtractor)
        $cached = $cache?->read($windowDays, $scopes);
        if ($cached !== null && !empty($cached['file_churn'])) {
            // Use cached per-file churn, resolve to modules
            foreach ($cached['file_churn'] as $filePath => $count) {
                $absPath = $repoPath . '/' . $filePath;
                $module = is_file($absPath) ? $this->resolveModuleFromFile($absPath) : $this->resolveModule(
                    preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $filePath, $m) ? $m[1] . '\\' . $m[2] : ''
                );
                if ($module !== 'unknown') {
                    $this->addSignal($signals, $module, 'churn_total', $count);
                }
            }
            return;
        }

        // Fall back to git log with configured window
        $sinceArg = escapeshellarg("--since={$windowDays} days ago");

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $cmd = sprintf(
                'cd %s && git log --name-only --pretty=format: %s -- %s 2>/dev/null | sort | uniq -c | sort -rn | head -200',
                escapeshellarg($repoPath),
                $sinceArg,
                escapeshellarg(trim($scope, '/'))
            );

            $output = @shell_exec($cmd);
            if ($output === null) {
                continue;
            }

            foreach (explode("\n", trim($output)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $match)) {
                    $count = (int) $match[1];
                    $filePath = $match[2];
                    $absPath = $repoPath . '/' . $filePath;
                    $module = is_file($absPath) ? $this->resolveModuleFromFile($absPath) : $this->resolveModule(
                        preg_match('#(?:app/code)/([^/]+)/([^/]+)/#', $filePath, $m) ? $m[1] . '\\' . $m[2] : ''
                    );
                    if ($module !== 'unknown') {
                        $this->addSignal($signals, $module, 'churn_total', $count);
                    }
                }
            }
        }
    }

    private function collectDeviationSignals(string $repoPath, array $scopes, array &$signals): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('*.php')->sortByName();

            foreach ($finder as $file) {
                $module = $this->moduleIdFromPath(
                    str_replace($repoPath . '/', '', $file->getRealPath())
                );

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                // Check for ObjectManager usage
                if (preg_match('/ObjectManager\s*::\s*getInstance\s*\(/i', $content)) {
                    $this->addSignal($signals, $module, 'deviation_count', 1);
                }

                // Check for direct SQL outside ResourceModel
                $relativePath = str_replace($repoPath . '/', '', $file->getRealPath());
                if (!str_contains($relativePath, '/ResourceModel/') &&
                    !str_contains($relativePath, '/Setup/') &&
                    preg_match('/->getConnection\s*\(\s*\)\s*->\s*(query|exec|fetchAll|fetchRow)\s*\(/i', $content)) {
                    $this->addSignal($signals, $module, 'deviation_count', 1);
                }
            }
        }
    }

    private function collectSizeSignals(string $repoPath, array $scopes, array &$signals): void
    {
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('*.php')
                ->exclude(['Test', 'tests'])
                ->sortByName();

            foreach ($finder as $file) {
                $module = $this->moduleIdFromPath(
                    str_replace($repoPath . '/', '', $file->getRealPath())
                );
                $this->addSignal($signals, $module, 'file_count', 1);
            }
        }
    }

    /**
     * Compute modifiability risk score per module.
     *
     * Score = weighted average of normalized signals:
     * - coupling_refs: 0.20
     * - plugin_count: 0.20
     * - core_override_count: 0.15
     * - churn_total: 0.15
     * - deviation_count: 0.15
     * - file_count: 0.15
     */
    private function computeModifiabilityScores(array $moduleSignals): array
    {
        if (empty($moduleSignals)) {
            return [];
        }

        $weights = [
            'coupling_refs' => 0.20,
            'plugin_count' => 0.20,
            'core_override_count' => 0.15,
            'churn_total' => 0.15,
            'deviation_count' => 0.15,
            'file_count' => 0.15,
        ];

        // Find max values for normalization
        $maxValues = [];
        foreach ($weights as $signal => $weight) {
            $maxValues[$signal] = 1;
            foreach ($moduleSignals as $signals) {
                $val = $signals[$signal] ?? 0;
                if ($val > $maxValues[$signal]) {
                    $maxValues[$signal] = $val;
                }
            }
        }

        $metrics = [];
        foreach ($moduleSignals as $module => $signals) {
            $score = 0;
            $reasons = [];

            foreach ($weights as $signal => $weight) {
                $raw = $signals[$signal] ?? 0;
                $normalized = $raw / $maxValues[$signal];
                $contribution = $normalized * $weight;
                $score += $contribution;

                if ($normalized > 0.5) {
                    $reasons[] = $signal . ': ' . $raw;
                }
            }

            $metrics[] = [
                'module' => $module,
                'modifiability_risk_score' => round($score, 3),
                'signals' => [
                    'coupling_refs' => $signals['coupling_refs'] ?? 0,
                    'plugin_count' => $signals['plugin_count'] ?? 0,
                    'core_override_count' => $signals['core_override_count'] ?? 0,
                    'preference_count' => $signals['preference_count'] ?? 0,
                    'churn_total' => $signals['churn_total'] ?? 0,
                    'deviation_count' => $signals['deviation_count'] ?? 0,
                    'file_count' => $signals['file_count'] ?? 0,
                ],
                'reasons' => $reasons,
                'evidence' => [Evidence::fromInference("modifiability score {$score} for {$module}: " . implode(', ', $reasons))->toArray()],
            ];
        }

        return $metrics;
    }

    private function addSignal(array &$signals, string $module, string $key, int $value): void
    {
        if ($module === 'unknown') {
            return;
        }
        if (!isset($signals[$module])) {
            $signals[$module] = [];
        }
        $signals[$module][$key] = ($signals[$module][$key] ?? 0) + $value;
    }

    private function avg(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return round(array_sum($values) / count($values), 3);
    }
}

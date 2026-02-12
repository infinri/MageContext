<?php

declare(strict_types=1);

namespace MageContext\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'diff',
    description: 'Compare two compiled context bundles and detect architectural regressions'
)]
class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'old',
                InputArgument::REQUIRED,
                'Path to the old (baseline) .ai-context directory'
            )
            ->addArgument(
                'new',
                InputArgument::REQUIRED,
                'Path to the new .ai-context directory'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: text, json, or markdown',
                'text'
            )
            ->addOption(
                'out',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path',
                ''
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $oldDir = $this->resolveDir($input->getArgument('old'));
        $newDir = $this->resolveDir($input->getArgument('new'));
        $format = $input->getOption('format');
        $outPath = $input->getOption('out');

        if (!is_dir($oldDir)) {
            $io->error("Old context directory not found: {$oldDir}");
            return Command::FAILURE;
        }
        if (!is_dir($newDir)) {
            $io->error("New context directory not found: {$newDir}");
            return Command::FAILURE;
        }

        $io->title('Context Diff');
        $io->text([
            "Old: <info>{$oldDir}</info>",
            "New: <info>{$newDir}</info>",
        ]);

        // Load both bundles
        $oldData = $this->loadBundle($oldDir);
        $newData = $this->loadBundle($newDir);

        if (empty($oldData) || empty($newData)) {
            $io->error('Failed to load one or both context bundles.');
            return Command::FAILURE;
        }

        // Run diff analysis
        $diff = $this->computeDiff($oldData, $newData);

        // Output results
        $rendered = $this->render($diff, $format);

        if ($outPath !== '' && $outPath !== null) {
            $dir = dirname($outPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outPath, $rendered);
            $io->text("Diff written to: <info>{$outPath}</info>");
        } else {
            $output->writeln($rendered);
        }

        // Summary
        $total = $diff['summary']['total_changes'] ?? 0;
        $regressions = $diff['summary']['regressions'] ?? 0;

        $io->newLine();
        if ($regressions > 0) {
            $io->warning("{$total} changes detected, {$regressions} regression(s).");
            return Command::FAILURE;
        }

        $io->success("{$total} changes detected, no regressions.");
        return Command::SUCCESS;
    }

    /**
     * Load all JSON files from a compiled context bundle.
     *
     * @return array<string, array>
     */
    private function loadBundle(string $dir): array
    {
        $data = [];

        $manifestPath = $dir . '/manifest.json';
        if (!is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        foreach ($manifest['files'] ?? [] as $relativePath) {
            $fullPath = $dir . '/' . $relativePath;
            if (!is_file($fullPath)) {
                continue;
            }

            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            if ($ext === 'json') {
                $key = pathinfo($relativePath, PATHINFO_FILENAME);
                $data[$key] = json_decode(file_get_contents($fullPath), true) ?? [];
            }
        }

        return $data;
    }

    /**
     * Compute a comprehensive diff between old and new bundles.
     */
    private function computeDiff(array $oldData, array $newData): array
    {
        $changes = [];
        $regressions = 0;

        // 1. Module count changes
        $oldModules = $oldData['modules']['summary']['total_modules'] ?? 0;
        $newModules = $newData['modules']['summary']['total_modules'] ?? 0;
        if ($oldModules !== $newModules) {
            $changes[] = [
                'category' => 'modules',
                'type' => 'count_change',
                'description' => "Module count: {$oldModules} → {$newModules}",
                'old_value' => $oldModules,
                'new_value' => $newModules,
                'regression' => false,
            ];
        }

        // 2. New circular dependencies
        $oldCycles = count($oldData['architectural_debt']['cycles'] ?? []);
        $newCycles = count($newData['architectural_debt']['cycles'] ?? []);
        if ($newCycles > $oldCycles) {
            $changes[] = [
                'category' => 'architectural_debt',
                'type' => 'new_cycles',
                'description' => "Circular dependencies increased: {$oldCycles} → {$newCycles}",
                'old_value' => $oldCycles,
                'new_value' => $newCycles,
                'regression' => true,
            ];
            $regressions++;
        } elseif ($newCycles < $oldCycles) {
            $changes[] = [
                'category' => 'architectural_debt',
                'type' => 'resolved_cycles',
                'description' => "Circular dependencies decreased: {$oldCycles} → {$newCycles}",
                'old_value' => $oldCycles,
                'new_value' => $newCycles,
                'regression' => false,
            ];
        }

        // 3. God module changes
        $oldGods = count($oldData['architectural_debt']['god_modules'] ?? []);
        $newGods = count($newData['architectural_debt']['god_modules'] ?? []);
        if ($newGods > $oldGods) {
            $changes[] = [
                'category' => 'architectural_debt',
                'type' => 'new_god_modules',
                'description' => "God modules increased: {$oldGods} → {$newGods}",
                'old_value' => $oldGods,
                'new_value' => $newGods,
                'regression' => true,
            ];
            $regressions++;
        }

        // 4. Plugin depth changes
        $oldMaxDepth = $this->extractMaxPluginDepth($oldData);
        $newMaxDepth = $this->extractMaxPluginDepth($newData);
        if ($newMaxDepth > $oldMaxDepth) {
            $changes[] = [
                'category' => 'performance',
                'type' => 'increased_plugin_depth',
                'description' => "Max plugin depth increased: {$oldMaxDepth} → {$newMaxDepth}",
                'old_value' => $oldMaxDepth,
                'new_value' => $newMaxDepth,
                'regression' => $newMaxDepth > 5,
            ];
            if ($newMaxDepth > 5) {
                $regressions++;
            }
        }

        // 5. Layer violation changes
        $oldViolations = count($oldData['layer_classification']['violations'] ?? []);
        $newViolations = count($newData['layer_classification']['violations'] ?? []);
        if ($newViolations > $oldViolations) {
            $changes[] = [
                'category' => 'layer_classification',
                'type' => 'new_violations',
                'description' => "Layer violations increased: {$oldViolations} → {$newViolations}",
                'old_value' => $oldViolations,
                'new_value' => $newViolations,
                'regression' => true,
            ];
            $regressions++;
        } elseif ($newViolations < $oldViolations) {
            $changes[] = [
                'category' => 'layer_classification',
                'type' => 'resolved_violations',
                'description' => "Layer violations decreased: {$oldViolations} → {$newViolations}",
                'old_value' => $oldViolations,
                'new_value' => $newViolations,
                'regression' => false,
            ];
        }

        // 6. Deviation count changes
        $oldDeviations = $oldData['custom_deviations']['summary']['total_deviations'] ?? 0;
        $newDeviations = $newData['custom_deviations']['summary']['total_deviations'] ?? 0;
        if ($newDeviations > $oldDeviations) {
            $changes[] = [
                'category' => 'deviations',
                'type' => 'new_deviations',
                'description' => "Deviations increased: {$oldDeviations} → {$newDeviations}",
                'old_value' => $oldDeviations,
                'new_value' => $newDeviations,
                'regression' => true,
            ];
            $regressions++;
        }

        // 7. Modifiability risk changes
        $oldAvgRisk = $oldData['modifiability']['summary']['avg_risk_score'] ?? 0;
        $newAvgRisk = $newData['modifiability']['summary']['avg_risk_score'] ?? 0;
        $riskDelta = round($newAvgRisk - $oldAvgRisk, 3);
        if (abs($riskDelta) > 0.01) {
            $dir = $riskDelta > 0 ? 'increased' : 'decreased';
            $changes[] = [
                'category' => 'modifiability',
                'type' => 'risk_change',
                'description' => "Average modifiability risk {$dir}: {$oldAvgRisk} → {$newAvgRisk} (Δ{$riskDelta})",
                'old_value' => $oldAvgRisk,
                'new_value' => $newAvgRisk,
                'regression' => $riskDelta > 0.05,
            ];
            if ($riskDelta > 0.05) {
                $regressions++;
            }
        }

        // 8. Performance indicator changes
        $oldPerfCount = $oldData['performance']['summary']['total_indicators'] ?? 0;
        $newPerfCount = $newData['performance']['summary']['total_indicators'] ?? 0;
        if ($newPerfCount > $oldPerfCount) {
            $changes[] = [
                'category' => 'performance',
                'type' => 'new_indicators',
                'description' => "Performance risk indicators increased: {$oldPerfCount} → {$newPerfCount}",
                'old_value' => $oldPerfCount,
                'new_value' => $newPerfCount,
                'regression' => true,
            ];
            $regressions++;
        }

        // 9. Hotspot changes (new high-risk modules)
        $oldHotspots = $oldData['hotspot_ranking']['summary']['high_risk_hotspots'] ?? 0;
        $newHotspots = $newData['hotspot_ranking']['summary']['high_risk_hotspots'] ?? 0;
        if ($newHotspots > $oldHotspots) {
            $changes[] = [
                'category' => 'hotspot_ranking',
                'type' => 'emerging_hotspots',
                'description' => "High-risk hotspots increased: {$oldHotspots} → {$newHotspots}",
                'old_value' => $oldHotspots,
                'new_value' => $newHotspots,
                'regression' => true,
            ];
            $regressions++;
        }

        // 10. New/removed modules
        $oldModuleNames = array_column($oldData['modules']['modules'] ?? [], 'name');
        $newModuleNames = array_column($newData['modules']['modules'] ?? [], 'name');
        $addedModules = array_diff($newModuleNames, $oldModuleNames);
        $removedModules = array_diff($oldModuleNames, $newModuleNames);

        foreach ($addedModules as $mod) {
            $changes[] = [
                'category' => 'modules',
                'type' => 'module_added',
                'description' => "Module added: {$mod}",
                'old_value' => null,
                'new_value' => $mod,
                'regression' => false,
            ];
        }
        foreach ($removedModules as $mod) {
            $changes[] = [
                'category' => 'modules',
                'type' => 'module_removed',
                'description' => "Module removed: {$mod}",
                'old_value' => $mod,
                'new_value' => null,
                'regression' => false,
            ];
        }

        return [
            'changes' => $changes,
            'summary' => [
                'total_changes' => count($changes),
                'regressions' => $regressions,
                'improvements' => count(array_filter($changes, fn($c) =>
                    !$c['regression'] &&
                    in_array($c['type'], ['resolved_cycles', 'resolved_violations'], true)
                )),
                'categories' => $this->countByField($changes, 'category'),
            ],
        ];
    }

    private function extractMaxPluginDepth(array $data): int
    {
        $max = 0;
        foreach ($data['performance']['indicators'] ?? [] as $ind) {
            if (($ind['type'] ?? '') === 'deep_plugin_stack') {
                $depth = $ind['depth'] ?? 0;
                if ($depth > $max) {
                    $max = $depth;
                }
            }
        }
        return $max;
    }

    /**
     * Render the diff in the requested format.
     */
    private function render(array $diff, string $format): string
    {
        return match ($format) {
            'json' => json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'markdown' => $this->renderMarkdown($diff),
            default => $this->renderText($diff),
        };
    }

    private function renderText(array $diff): string
    {
        $out = "";
        $summary = $diff['summary'];

        $out .= "=== Context Diff Summary ===\n";
        $out .= "Total changes: {$summary['total_changes']}\n";
        $out .= "Regressions:   {$summary['regressions']}\n";
        $out .= "Improvements:  {$summary['improvements']}\n\n";

        if (!empty($diff['changes'])) {
            $out .= "--- Changes ---\n";
            foreach ($diff['changes'] as $change) {
                $marker = $change['regression'] ? '[REGRESSION]' : '[OK]';
                $out .= "  {$marker} {$change['description']}\n";
            }
        }

        return $out;
    }

    private function renderMarkdown(array $diff): string
    {
        $md = "# Context Diff Report\n\n";
        $summary = $diff['summary'];

        $md .= "- **Total changes:** {$summary['total_changes']}\n";
        $md .= "- **Regressions:** {$summary['regressions']}\n";
        $md .= "- **Improvements:** {$summary['improvements']}\n\n";

        // Regressions first
        $regressions = array_filter($diff['changes'], fn($c) => $c['regression']);
        if (!empty($regressions)) {
            $md .= "## Regressions\n\n";
            foreach ($regressions as $change) {
                $md .= "- **[{$change['category']}]** {$change['description']}\n";
            }
            $md .= "\n";
        }

        // Other changes
        $others = array_filter($diff['changes'], fn($c) => !$c['regression']);
        if (!empty($others)) {
            $md .= "## Other Changes\n\n";
            foreach ($others as $change) {
                $md .= "- **[{$change['category']}]** {$change['description']}\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function resolveDir(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            return getcwd() . '/' . $path;
        }
        return $path;
    }

    private function countByField(array $items, string $field): array
    {
        $counts = [];
        foreach ($items as $item) {
            $key = $item[$field] ?? 'unknown';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }
}

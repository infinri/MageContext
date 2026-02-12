<?php

declare(strict_types=1);

namespace MageContext\Command;

use MageContext\Resolver\GuideResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'guide',
    description: 'Generate development guidance for a task within specific Magento areas'
)]
class GuideCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'task',
                't',
                InputOption::VALUE_REQUIRED,
                'Description of the development task'
            )
            ->addOption(
                'area',
                'a',
                InputOption::VALUE_REQUIRED,
                'Comma-separated Magento areas or module keywords to focus on (e.g., salesrule,checkout)'
            )
            ->addOption(
                'context-dir',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to the compiled .ai-context directory',
                '.ai-context'
            )
            ->addOption(
                'out',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (JSON or Markdown based on extension)',
                ''
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: json, markdown, or both',
                'markdown'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $task = $input->getOption('task') ?? '';
        $areaStr = $input->getOption('area') ?? '';
        $contextDir = $input->getOption('context-dir');
        $outPath = $input->getOption('out');
        $format = $input->getOption('format');

        if ($task === '' || $areaStr === '') {
            $io->error('Both --task and --area are required.');
            return Command::FAILURE;
        }

        $areas = array_map('trim', explode(',', $areaStr));

        if (!str_starts_with($contextDir, '/')) {
            $contextDir = getcwd() . '/' . $contextDir;
        }

        $io->title('Development Guide');
        $io->text([
            "Task:  <info>{$task}</info>",
            "Areas: <info>" . implode(', ', $areas) . "</info>",
        ]);

        $resolver = new GuideResolver($contextDir);
        try {
            $resolver->load();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $guide = $resolver->guide($task, $areas);

        // Output
        if ($outPath !== '' && $outPath !== null) {
            $this->writeOutput($guide, $outPath, $format, $io);
        } else {
            if ($format === 'json') {
                $output->writeln(json_encode($guide, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln($this->renderMarkdown($guide));
            }
        }

        $io->newLine();
        $io->success('Guide generated.');
        return Command::SUCCESS;
    }

    private function writeOutput(array $guide, string $outPath, string $format, SymfonyStyle $io): void
    {
        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = pathinfo($outPath, PATHINFO_EXTENSION);

        if ($format === 'both' || $ext === '') {
            $base = $ext === '' ? $outPath : substr($outPath, 0, -strlen($ext) - 1);
            file_put_contents($base . '.json', json_encode($guide, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($base . '.md', $this->renderMarkdown($guide));
            $io->text("Written to: <info>{$base}.json</info> and <info>{$base}.md</info>");
        } elseif ($ext === 'md' || $format === 'markdown') {
            file_put_contents($outPath, $this->renderMarkdown($guide));
            $io->text("Written to: <info>{$outPath}</info>");
        } else {
            file_put_contents($outPath, json_encode($guide, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $io->text("Written to: <info>{$outPath}</info>");
        }
    }

    private function renderMarkdown(array $guide): string
    {
        $md = "# Development Guide\n\n";
        $md .= "**Task:** {$guide['task']}\n\n";
        $md .= "**Areas:** " . implode(', ', $guide['areas']) . "\n\n";
        $md .= "---\n\n";

        // Where it belongs
        $md .= "## Where This Logic Belongs\n\n";
        foreach ($guide['where_it_belongs'] as $loc) {
            $md .= "- **{$loc['module']}**";
            if (!empty($loc['path'])) {
                $md .= " (`{$loc['path']}`)";
            }
            $md .= "\n  {$loc['suggestion']}\n\n";
        }

        // Extension points
        $md .= "## Extension Points Used in Your Project\n\n";
        if (empty($guide['extension_points'])) {
            $md .= "_No existing extension points found for these areas._\n\n";
        } else {
            foreach ($guide['extension_points'] as $type => $data) {
                $md .= "### " . ucfirst($type) . " ({$data['count']})\n\n";
                $md .= $data['description'] . "\n\n";

                if ($type === 'plugins' && !empty($data['by_target'])) {
                    foreach ($data['by_target'] as $target => $plugins) {
                        $md .= "**`{$target}`**\n";
                        foreach ($plugins as $p) {
                            $methods = !empty($p['methods']) ? implode(', ', $p['methods']) : 'none resolved';
                            $md .= "  - `{$p['plugin']}` ({$p['scope']}) — {$methods}\n";
                        }
                        $md .= "\n";
                    }
                } elseif ($type === 'observers' && !empty($data['by_event'])) {
                    foreach ($data['by_event'] as $event => $obs) {
                        $md .= "**`{$event}`**\n";
                        foreach ($obs as $o) {
                            $md .= "  - `{$o['observer']}` ({$o['scope']})\n";
                        }
                        $md .= "\n";
                    }
                } elseif ($type === 'preferences' && !empty($data['items'])) {
                    foreach ($data['items'] as $p) {
                        $md .= "- `{$p['interface']}` → `{$p['preference']}` ({$p['scope']})\n";
                    }
                    $md .= "\n";
                }
            }
        }

        // Patterns to follow
        $md .= "## Patterns Used (Follow These)\n\n";
        if (empty($guide['patterns_used'])) {
            $md .= "_No dominant patterns detected._\n\n";
        } else {
            foreach ($guide['patterns_used'] as $p) {
                $md .= "### {$p['pattern']}\n\n";
                $md .= "- **Usage:** {$p['usage']}\n";
                $md .= "- **Recommendation:** {$p['recommendation']}\n\n";
            }
        }

        // Patterns to avoid
        $md .= "## Patterns to Avoid\n\n";
        if (empty($guide['patterns_to_avoid'])) {
            $md .= "_No anti-patterns detected in this area._\n\n";
        } else {
            foreach ($guide['patterns_to_avoid'] as $ap) {
                $md .= "### {$ap['pattern']} ({$ap['severity']})\n\n";
                $md .= "- **Occurrences in project:** {$ap['occurrences']}\n";
                $md .= "- **Instruction:** {$ap['instruction']}\n";
                if (!empty($ap['examples_in_project'])) {
                    $md .= "- **Examples:** " . implode(', ', array_map(fn($f) => "`{$f}`", $ap['examples_in_project'])) . "\n";
                }
                $md .= "\n";
            }
        }

        // Related modules
        $md .= "## Related Modules\n\n";
        $related = $guide['related_modules'];
        if (!empty($related['primary'])) {
            $md .= "### Primary Modules\n\n";
            foreach ($related['primary'] as $mod) {
                $deps = !empty($mod['sequence_dependencies']) ? implode(', ', $mod['sequence_dependencies']) : 'none';
                $md .= "- **{$mod['name']}** (`{$mod['path']}`) — depends on: {$deps}\n";
            }
            $md .= "\n";
        }
        if (!empty($related['dependents'])) {
            $md .= "### Modules That Depend on This Area\n\n";
            foreach ($related['dependents'] as $dep) {
                $md .= "- **{$dep['name']}** (`{$dep['path']}`) — depends on {$dep['depends_on']}\n";
            }
            $md .= "\n";
        }

        // Test pointers
        $md .= "## Test Pointers\n\n";
        foreach ($guide['test_pointers'] as $tp) {
            $md .= "### {$tp['module']}\n\n";
            if (!empty($tp['test_directory'])) {
                $md .= "Test directory: `{$tp['test_directory']}`\n\n";
            }
            foreach ($tp['suggestions'] as $s) {
                $md .= "- {$s}\n";
            }
            $md .= "\n";
        }

        // Execution Context
        if (!empty($guide['execution_context'])) {
            $md .= "## Execution Context\n\n";
            $ctx = $guide['execution_context'];
            $md .= "**Entry points found:** {$ctx['count']}\n\n";
            foreach ($ctx['entry_points'] ?? [] as $ep) {
                $md .= "- **{$ep['type']}** `{$ep['class']}` ({$ep['module']})";
                if ($ep['plugin_depth'] > 0 || $ep['observer_count'] > 0) {
                    $md .= " — plugins: {$ep['plugin_depth']}, observers: {$ep['observer_count']}";
                }
                $md .= "\n";
            }
            $md .= "\n";
        }

        // Risk assessment
        $md .= "## Risk Assessment\n\n";
        $risk = $guide['risk_assessment'];
        $riskEmoji = match ($risk['level']) {
            'high' => '🔴',
            'medium' => '🟡',
            default => '🟢',
        };
        $md .= "**Risk Level:** {$riskEmoji} {$risk['level']}\n\n";
        $md .= "- **Deviations in area:** {$risk['total_deviations']} (critical: {$risk['critical']}, high: {$risk['high']})\n";
        $md .= "- **Plugin density:** {$risk['plugin_density']}\n";
        $md .= "- **Churn hotspots:** {$risk['churn_hotspots']}\n";
        if (isset($risk['high_risk_modules'])) {
            $md .= "- **High-risk modules (modifiability):** {$risk['high_risk_modules']}\n";
        }
        if (isset($risk['layer_violations'])) {
            $md .= "- **Layer violations:** {$risk['layer_violations']}\n";
        }
        if (isset($risk['debt_items'])) {
            $md .= "- **Architectural debt items:** {$risk['debt_items']}\n";
        }
        $md .= "\n";
        $md .= "**Reasons:**\n";
        foreach ($risk['reasons'] as $reason) {
            $md .= "- {$reason}\n";
        }
        $md .= "\n";

        return $md;
    }
}

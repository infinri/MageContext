<?php

declare(strict_types=1);

namespace MageContext\Command;

use MageContext\Resolver\ContextResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pack',
    description: 'Extract minimum relevant context from a compiled bundle for a specific issue or stack trace'
)]
class PackCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'issue',
                'i',
                InputOption::VALUE_REQUIRED,
                'Issue description or bug summary'
            )
            ->addOption(
                'trace',
                't',
                InputOption::VALUE_OPTIONAL,
                'Path to a stack trace or log file',
                ''
            )
            ->addOption(
                'keywords',
                'k',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated additional keywords to search for',
                ''
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
                InputOption::VALUE_REQUIRED,
                'Output path for the context pack (JSON file or directory)',
                ''
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: json, markdown, or both',
                'both'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $issueText = $input->getOption('issue') ?? '';
        $tracePath = $input->getOption('trace');
        $extraKeywords = $input->getOption('keywords');
        $contextDir = $input->getOption('context-dir');
        $outPath = $input->getOption('out');
        $format = $input->getOption('format');

        if ($issueText === '' && $tracePath === '' && $extraKeywords === '') {
            $io->error('At least one of --issue, --trace, or --keywords is required.');
            return Command::FAILURE;
        }

        // Resolve context-dir
        if (!str_starts_with($contextDir, '/')) {
            $contextDir = getcwd() . '/' . $contextDir;
        }

        $io->title('Context Pack');

        // Load stack trace from file if provided
        $stackTrace = '';
        if ($tracePath !== '' && $tracePath !== null) {
            if (!str_starts_with($tracePath, '/')) {
                $tracePath = getcwd() . '/' . $tracePath;
            }
            if (is_file($tracePath)) {
                $stackTrace = file_get_contents($tracePath);
                $io->text("Stack trace loaded from: <info>{$tracePath}</info>");
            } else {
                // Treat the value itself as inline trace text
                $stackTrace = $tracePath;
            }
        }

        // Build the resolver
        $resolver = new ContextResolver($contextDir);
        try {
            $resolver->load();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Extract keywords
        $keywords = $resolver->extractKeywords($issueText, $stackTrace);

        // Add manual keywords
        if ($extraKeywords !== '' && $extraKeywords !== null) {
            $manual = array_map('trim', explode(',', $extraKeywords));
            $keywords = array_unique(array_merge($keywords, array_filter($manual)));
        }

        if (empty($keywords)) {
            $io->warning('No searchable keywords could be extracted. Try adding --keywords.');
            return Command::FAILURE;
        }

        $io->text("Issue: <info>{$issueText}</info>");
        $io->text("Keywords extracted: <info>" . implode(', ', $keywords) . "</info>");
        $io->newLine();

        // Resolve context
        $context = $resolver->resolve($keywords);

        if (empty($context)) {
            $io->warning('No relevant context found for the given keywords.');
            return Command::SUCCESS;
        }

        // Build the pack
        $pack = [
            'meta' => [
                'generated_at' => date('c'),
                'issue' => $issueText,
                'keywords' => $keywords,
                'source_context_dir' => $contextDir,
            ],
            'context' => $context,
        ];

        // Display summary
        $io->section('Context Pack Summary');
        foreach ($context as $section => $data) {
            $count = $this->countSection($data);
            $io->text("  <comment>{$section}</comment>: <info>{$count} items</info>");
        }

        // Output
        if ($outPath !== '' && $outPath !== null) {
            $this->writeOutput($pack, $outPath, $format, $io, $issueText);
        } else {
            // Print to stdout
            if ($format === 'markdown' || $format === 'both') {
                $io->newLine();
                $io->section('Context Pack (Markdown)');
                $output->writeln($this->renderMarkdown($pack));
            }
            if ($format === 'json' || $format === 'both') {
                $io->newLine();
                $io->section('Context Pack (JSON)');
                $output->writeln(json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        $io->newLine();
        $io->success('Context pack ready.');
        return Command::SUCCESS;
    }

    private function writeOutput(array $pack, string $outPath, string $format, SymfonyStyle $io, string $issueText): void
    {
        if (is_dir($outPath) || str_ends_with($outPath, '/')) {
            if (!is_dir($outPath)) {
                mkdir($outPath, 0755, true);
            }
            $baseName = 'context-pack-' . date('Ymd-His');

            if ($format === 'json' || $format === 'both') {
                $jsonPath = $outPath . '/' . $baseName . '.json';
                file_put_contents($jsonPath, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $io->text("JSON written to: <info>{$jsonPath}</info>");
            }
            if ($format === 'markdown' || $format === 'both') {
                $mdPath = $outPath . '/' . $baseName . '.md';
                file_put_contents($mdPath, $this->renderMarkdown($pack));
                $io->text("Markdown written to: <info>{$mdPath}</info>");
            }
        } else {
            // Single file output
            $ext = pathinfo($outPath, PATHINFO_EXTENSION);
            if ($ext === 'md') {
                file_put_contents($outPath, $this->renderMarkdown($pack));
            } else {
                file_put_contents($outPath, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
            $io->text("Written to: <info>{$outPath}</info>");
        }
    }

    private function renderMarkdown(array $pack): string
    {
        $md = "# Context Pack\n\n";
        $md .= "**Issue:** {$pack['meta']['issue']}\n\n";
        $md .= "**Keywords:** " . implode(', ', $pack['meta']['keywords']) . "\n\n";
        $md .= "**Generated:** {$pack['meta']['generated_at']}\n\n";
        $md .= "---\n\n";

        foreach ($pack['context'] as $section => $data) {
            $title = ucwords(str_replace('_', ' ', $section));
            $md .= "## {$title}\n\n";
            $md .= $this->renderSectionMarkdown($section, $data);
            $md .= "\n";
        }

        return $md;
    }

    private function renderSectionMarkdown(string $section, mixed $data): string
    {
        if (!is_array($data)) {
            return "```\n{$data}\n```\n";
        }

        // Handle nested structures (plugins have matched_plugins + relevant_chains)
        if (isset($data['matched_plugins'])) {
            $md = "### Matched Plugins\n\n";
            $md .= $this->renderTable($data['matched_plugins'], ['target_class', 'plugin_class', 'scope', 'methods']);
            if (!empty($data['relevant_chains'])) {
                $md .= "\n### Plugin Chains (Execution Order)\n\n";
                foreach ($data['relevant_chains'] as $target => $chain) {
                    $md .= "**{$target}:**\n";
                    foreach ($chain as $i => $p) {
                        $order = $p['sort_order'] ?? '—';
                        $methods = implode(', ', $p['methods'] ?? []) ?: 'none';
                        $md .= "  " . ($i + 1) . ". `{$p['plugin_class']}` (order: {$order}) — {$methods}\n";
                    }
                    $md .= "\n";
                }
            }
            return $md;
        }

        // Handle db_schema nested (tables + patches)
        if (isset($data['tables']) || isset($data['patches'])) {
            $md = '';
            if (!empty($data['tables'])) {
                $md .= "### Tables\n\n";
                $md .= $this->renderTable($data['tables'], ['name', 'comment', 'source_file']);
            }
            if (!empty($data['patches'])) {
                $md .= "### Patches\n\n";
                $md .= $this->renderTable($data['patches'], ['class', 'type', 'source_file']);
            }
            return $md;
        }

        // Handle api nested (rest + graphql)
        if (isset($data['rest']) || isset($data['graphql'])) {
            $md = '';
            if (!empty($data['rest'])) {
                $md .= "### REST Endpoints\n\n";
                $md .= $this->renderTable($data['rest'], ['method', 'url', 'service_class', 'service_method']);
            }
            if (!empty($data['graphql'])) {
                $md .= "### GraphQL Types\n\n";
                $md .= $this->renderTable($data['graphql'], ['kind', 'name', 'resolver_class']);
            }
            return $md;
        }

        // Flat arrays
        if (!empty($data) && isset($data[0]) && is_array($data[0])) {
            $keys = $this->pickDisplayKeys($section, $data[0]);
            return $this->renderTable($data, $keys);
        }

        return "```json\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n";
    }

    private function renderTable(array $rows, array $keys): string
    {
        if (empty($rows)) {
            return "_None found._\n";
        }

        // Header
        $md = '| ' . implode(' | ', array_map(fn($k) => ucwords(str_replace('_', ' ', $k)), $keys)) . " |\n";
        $md .= '| ' . implode(' | ', array_map(fn() => '---', $keys)) . " |\n";

        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                $val = $row[$key] ?? '';
                if (is_array($val)) {
                    $val = implode(', ', array_map(
                        fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : (string) $v,
                        $val
                    ));
                }
                $val = str_replace('|', '\\|', (string) $val);
                if (strlen($val) > 80) {
                    $val = substr($val, 0, 77) . '...';
                }
                $cells[] = "`{$val}`";
            }
            $md .= '| ' . implode(' | ', $cells) . " |\n";
        }

        return $md;
    }

    private function pickDisplayKeys(string $section, array $sample): array
    {
        $sectionKeys = [
            'modules' => ['name', 'path', 'status', 'dependencies'],
            'di_preferences' => ['interface', 'preference', 'scope', 'source_file'],
            'observers' => ['event_name', 'observer_class', 'scope', 'source_file'],
            'layout' => ['handle', 'type', 'name', 'class', 'source_file'],
            'route_map' => ['route_id', 'front_name', 'area', 'declared_by'],
            'deviations' => ['severity', 'type', 'message', 'source_file'],
            'execution_paths' => ['type', 'entry_class', 'module', 'scenario'],
            'layer_violations' => ['from', 'from_layer', 'to', 'to_layer', 'module'],
            'architectural_debt' => ['type', 'severity', 'description'],
            'modifiability' => ['module', 'modifiability_risk_score'],
        ];

        if (isset($sectionKeys[$section])) {
            return array_filter($sectionKeys[$section], fn($k) => array_key_exists($k, $sample));
        }

        // Fallback: pick first 4 string keys
        $picked = [];
        foreach (array_keys($sample) as $key) {
            if (count($picked) >= 4) {
                break;
            }
            $picked[] = $key;
        }
        return $picked;
    }

    private function countSection(mixed $data): int
    {
        if (!is_array($data)) {
            return 1;
        }

        // Nested structures
        if (isset($data['matched_plugins'])) {
            return count($data['matched_plugins']);
        }
        if (isset($data['tables'])) {
            return count($data['tables'] ?? []) + count($data['patches'] ?? []);
        }
        if (isset($data['rest'])) {
            return count($data['rest'] ?? []) + count($data['graphql'] ?? []);
        }

        return count($data);
    }
}

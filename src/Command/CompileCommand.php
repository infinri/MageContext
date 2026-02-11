<?php

declare(strict_types=1);

namespace MageContext\Command;

use MageContext\Extractor\ExtractorRegistry;
use MageContext\Extractor\Magento\DiPreferenceExtractor;
use MageContext\Extractor\Magento\ModuleGraphExtractor;
use MageContext\Extractor\Magento\ObserverExtractor;
use MageContext\Extractor\Magento\PluginExtractor;
use MageContext\Extractor\Universal\GitChurnExtractor;
use MageContext\Extractor\Universal\RepoMapExtractor;
use MageContext\Output\OutputWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'compile',
    description: 'Compile an AI-ready context bundle from a Magento repository'
)]
class CompileCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->setDescription('Compile an AI-ready context bundle from a Magento repository')
            ->addOption(
                'repo',
                'r',
                InputOption::VALUE_REQUIRED,
                'Path to the repository root',
                '.'
            )
            ->addOption(
                'scope',
                's',
                InputOption::VALUE_REQUIRED,
                'Comma-separated directories to scan (relative to repo root)',
                'app/code,app/design'
            )
            ->addOption(
                'out',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for the context bundle',
                '.ai-context'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: json or jsonl',
                'json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repoPath = realpath($input->getOption('repo'));
        if ($repoPath === false || !is_dir($repoPath)) {
            $io->error('Repository path does not exist: ' . $input->getOption('repo'));
            return Command::FAILURE;
        }

        $scopes = array_map('trim', explode(',', $input->getOption('scope')));
        $outDir = $input->getOption('out');
        $format = $input->getOption('format');

        // Resolve output dir relative to repo if not absolute
        if (!str_starts_with($outDir, '/')) {
            $outDir = $repoPath . '/' . $outDir;
        }

        $io->title('Context Compiler');
        $io->text([
            "Repository: <info>$repoPath</info>",
            "Scopes:     <info>" . implode(', ', $scopes) . "</info>",
            "Output:     <info>$outDir</info>",
            "Format:     <info>$format</info>",
        ]);

        $startTime = microtime(true);

        // Build registry with all available extractors
        $registry = $this->buildRegistry();
        $writer = new OutputWriter($outDir);
        $writer->prepare();

        $extractorResults = [];
        $io->section('Running extractors');

        foreach ($registry->all() as $extractor) {
            $io->text("  → <comment>{$extractor->getName()}</comment>: {$extractor->getDescription()}");

            try {
                $data = $extractor->extract($repoPath, $scopes);
                $itemCount = $this->countItems($data);

                // Write extractor output
                $outputFile = 'magento/' . $extractor->getName() . '.' . $format;
                if ($format === 'jsonl') {
                    $writer->writeJsonl($outputFile, $this->flattenForJsonl($data));
                } else {
                    $writer->writeJson($outputFile, $data);
                }

                $extractorResults[$extractor->getName()] = [
                    'status' => 'ok',
                    'item_count' => $itemCount,
                    'output_files' => [$outputFile],
                ];

                $io->text("    ✓ <info>$itemCount items extracted</info>");
            } catch (\Throwable $e) {
                $extractorResults[$extractor->getName()] = [
                    'status' => 'error',
                    'item_count' => 0,
                    'error' => $e->getMessage(),
                    'output_files' => [],
                ];

                $io->text("    ✗ <error>Error: {$e->getMessage()}</error>");
            }
        }

        $duration = microtime(true) - $startTime;
        $writer->writeManifest($extractorResults, $duration);

        $io->newLine();
        $io->success(sprintf(
            'Context bundle compiled in %.2fs → %s',
            $duration,
            $outDir
        ));

        return Command::SUCCESS;
    }

    private function buildRegistry(): ExtractorRegistry
    {
        $registry = new ExtractorRegistry();

        // Magento extractors
        $registry->register(new ModuleGraphExtractor());
        $registry->register(new DiPreferenceExtractor());
        $registry->register(new PluginExtractor());
        $registry->register(new ObserverExtractor());

        // Universal analyzers
        $registry->register(new RepoMapExtractor());
        $registry->register(new GitChurnExtractor());

        return $registry;
    }

    private function countItems(array $data): int
    {
        $count = 0;
        foreach ($data as $value) {
            if (is_array($value)) {
                $count += count($value);
            } else {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Flatten nested data into a list of records suitable for JSONL output.
     */
    private function flattenForJsonl(array $data): array
    {
        $records = [];
        foreach ($data as $section => $items) {
            if (is_array($items) && !$this->isAssoc($items)) {
                foreach ($items as $item) {
                    $records[] = array_merge(['_section' => $section], is_array($item) ? $item : ['value' => $item]);
                }
            } else {
                $records[] = ['_section' => $section, 'data' => $items];
            }
        }
        return $records;
    }

    private function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

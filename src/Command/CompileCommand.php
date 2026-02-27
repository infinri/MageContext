<?php

declare(strict_types=1);

namespace MageContext\Command;

use MageContext\Cache\ChurnCache;
use MageContext\Config\CompilerConfig;
use MageContext\Extractor\CompilationContext;
use MageContext\Extractor\ExtractorRegistry;
use MageContext\Extractor\Magento\HotspotRankingExtractor;
use MageContext\Extractor\Universal\FileIndexExtractor;
use MageContext\Extractor\Universal\GitChurnExtractor;
use MageContext\Extractor\Universal\RepoMapExtractor;
use MageContext\Extractor\Universal\SymbolIndexExtractor;
use MageContext\Output\IndexBuilder;
use MageContext\Identity\ModuleResolver;
use MageContext\Identity\WarningCollector;
use MageContext\Output\AiDigestGenerator;
use MageContext\Output\BundleValidator;
use MageContext\Output\OutputWriter;
use MageContext\Output\ScenarioBundleGenerator;
use MageContext\Output\SchemaGenerator;
use MageContext\Target\GenericTarget;
use MageContext\Target\MagentoTarget;
use MageContext\Target\TargetInterface;
use MageContext\Target\TargetRegistry;
use MageContext\Util\ArrayUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'compile',
    description: 'Compile an AI-ready context bundle from a repository'
)]
class CompileCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->setDescription('Compile an AI-ready context bundle from a repository')
            ->addOption(
                'repo',
                'r',
                InputOption::VALUE_REQUIRED,
                'Path to the repository root',
                '.'
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_OPTIONAL,
                'Target platform: magento, generic, or auto (auto-detect)',
                'auto'
            )
            ->addOption(
                'scope',
                's',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated directories to scan (defaults to target-specific scopes)',
                ''
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
            )
            ->addOption(
                'ci',
                null,
                InputOption::VALUE_NONE,
                'CI mode: write ci_summary.json and exit with non-zero on threshold violations'
            )
            ->addOption(
                'max-violations',
                null,
                InputOption::VALUE_REQUIRED,
                'CI threshold: max allowed layer violations (default: unlimited)',
                ''
            )
            ->addOption(
                'max-cycles',
                null,
                InputOption::VALUE_REQUIRED,
                'CI threshold: max allowed circular dependencies (default: unlimited)',
                ''
            )
            ->addOption(
                'max-deviations',
                null,
                InputOption::VALUE_REQUIRED,
                'CI threshold: max allowed deviations (default: unlimited)',
                ''
            )
            ->addOption(
                'max-risk',
                null,
                InputOption::VALUE_REQUIRED,
                'CI threshold: max average modifiability risk score 0.0-1.0 (default: unlimited)',
                ''
            )
            ->addOption(
                'skip-determinism-check',
                null,
                InputOption::VALUE_NONE,
                'Skip determinism verification (saves ~2x cost on dev machines; CI should NOT use this)'
            )
            ->addOption(
                'churn-window',
                null,
                InputOption::VALUE_REQUIRED,
                'Override churn window in days (default: 365, use 0 to disable churn)',
                ''
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

        $targetName = $input->getOption('target') ?? 'auto';
        $scopeStr = $input->getOption('scope') ?? '';
        $outDir = $input->getOption('out');
        $format = $input->getOption('format');

        // Resolve target
        $targetRegistry = $this->buildTargetRegistry();
        if ($targetName === 'auto') {
            $target = $targetRegistry->detect($repoPath);
        } else {
            $target = $targetRegistry->get($targetName);
            if ($target === null) {
                $io->error("Unknown target: {$targetName}. Available: " . implode(', ', $targetRegistry->names()));
                return Command::FAILURE;
            }
        }

        // Use target default scopes if none specified
        $scopes = $scopeStr !== ''
            ? array_map('trim', explode(',', $scopeStr))
            : $target->getDefaultScopes();

        // Resolve output dir relative to repo if not absolute
        if (!str_starts_with($outDir, '/')) {
            $outDir = $repoPath . '/' . $outDir;
        }

        $io->title('Context Compiler');
        $io->text([
            "Repository: <info>$repoPath</info>",
            "Target:     <info>{$target->getName()}</info> ({$target->getDescription()})",
            "Scopes:     <info>" . implode(', ', $scopes) . "</info>",
            "Output:     <info>$outDir</info>",
            "Format:     <info>$format</info>",
        ]);

        $startTime = microtime(true);

        // Build CLI overrides for config
        $cliOverrides = [];
        $churnWindowOpt = $input->getOption('churn-window');
        if ($churnWindowOpt !== '' && $churnWindowOpt !== null) {
            $churnDays = (int) $churnWindowOpt;
            if ($churnDays === 0) {
                $cliOverrides['churn'] = ['enabled' => false];
            } else {
                $cliOverrides['churn'] = ['window_days' => $churnDays];
            }
        }

        // Build foundation services
        $config = CompilerConfig::load($repoPath, $cliOverrides);
        $moduleResolver = new ModuleResolver($repoPath);
        $moduleResolver->build($scopes);

        // Build registry from target + universal extractors
        $registry = $this->buildRegistry($target);
        $writer = new OutputWriter($outDir, $repoPath, $scopes);
        $writer->prepare();

        // Create compilation context with foundation services
        $warningCollector = new WarningCollector();
        $compilationContext = new CompilationContext(
            $repoPath,
            $scopes,
            $moduleResolver,
            $config,
            $writer->getRepoCommit(),
            $warningCollector
        );

        [$extractorResults, $allExtractedData] = $this->runExtractors(
            $registry, $compilationContext, $writer, $config, $repoPath, $scopes, $format, $io
        );

        $duration = microtime(true) - $startTime;

        $this->generatePostExtractorOutputs(
            $allExtractedData, $extractorResults, $writer, $repoPath, $target, $format, $duration, $io
        );

        // Set integrity score denominators from same discovery logic as dep graph
        $warningCollector->setTotals(
            max(1, $moduleResolver->getDiscoveredClassCount()),
            max(1, $this->countDiTargets($allExtractedData))
        );

        $this->runValidation(
            $config, $writer, $outDir, $extractorResults, $allExtractedData, $input, $io
        );

        $writer->writeManifest($extractorResults, $duration, $repoPath, $scopes, $target->getName(), $warningCollector, $config);

        // CI mode: generate summary and check thresholds
        $ciMode = $input->getOption('ci');
        if ($ciMode) {
            $io->section('CI Analysis');
            $ciResult = $this->runCiChecks($input, $allExtractedData, $writer, $io);
            if ($ciResult !== Command::SUCCESS) {
                return $ciResult;
            }
        }

        $io->newLine();
        $io->success(sprintf(
            'Context bundle compiled in %.2fs → %s',
            $duration,
            $outDir
        ));

        return Command::SUCCESS;
    }

    private function buildTargetRegistry(): TargetRegistry
    {
        $registry = new TargetRegistry();
        $registry->register(new MagentoTarget());
        $registry->register(new GenericTarget());
        return $registry;
    }

    private function buildRegistry(TargetInterface $target): ExtractorRegistry
    {
        $registry = new ExtractorRegistry();

        // Target-specific extractors
        foreach ($target->getExtractors() as $extractor) {
            $registry->register($extractor);
        }

        // Universal analyzers (always included)
        $registry->register(new RepoMapExtractor());
        $registry->register(new GitChurnExtractor());

        // C.1+C.2: Index extractors (must run after target extractors)
        $registry->register(new SymbolIndexExtractor());
        $registry->register(new FileIndexExtractor());

        return $registry;
    }

    /**
     * Run all registered extractors and collect results.
     *
     * @return array{0: array, 1: array} [$extractorResults, $allExtractedData]
     */
    private function runExtractors(
        ExtractorRegistry $registry,
        CompilationContext $compilationContext,
        OutputWriter $writer,
        CompilerConfig $config,
        string $repoPath,
        array $scopes,
        string $format,
        SymfonyStyle $io
    ): array {
        $extractorResults = [];
        $allExtractedData = [];
        $io->section('Running extractors');

        $churnEnabled = $config->isChurnEnabled();
        $gitChurnExtractor = null;
        $hotspotExtractor = null;

        foreach ($registry->all() as $extractor) {
            // Skip churn-dependent extractors when churn is disabled
            if (!$churnEnabled && in_array($extractor->getName(), ['git_churn_hotspots', 'hotspot_ranking'], true)) {
                $io->text("  → <comment>{$extractor->getName()}</comment>: <fg=yellow>skipped (churn disabled)</>");
                $extractorResults[$extractor->getName()] = [
                    'status' => 'skipped',
                    'item_count' => 0,
                    'duration_ms' => 0,
                    'view' => $extractor->getOutputView(),
                    'output_files' => [],
                    'reason' => 'churn disabled via config',
                ];
                continue;
            }

            // Track churn extractors for cache writing
            if ($extractor instanceof GitChurnExtractor) {
                $gitChurnExtractor = $extractor;
            }
            if ($extractor instanceof HotspotRankingExtractor) {
                $hotspotExtractor = $extractor;
            }

            $io->text("  → <comment>{$extractor->getName()}</comment>: {$extractor->getDescription()}");

            // Inject foundation services
            $extractor->setContext($compilationContext);

            $extractorStart = microtime(true);

            try {
                $data = $extractor->extract($repoPath, $scopes);
                $extractorDurationMs = round((microtime(true) - $extractorStart) * 1000, 1);
                $itemCount = $this->countItems($data);

                // Route output to correct view directory
                $viewDir = $extractor->getOutputView();
                $outputFile = ($viewDir === '.' ? '' : $viewDir . '/') . $extractor->getName() . '.' . $format;
                if ($format === 'jsonl') {
                    $writer->writeJsonl($outputFile, $this->flattenForJsonl($data));
                } else {
                    $writer->writeJson($outputFile, $data);
                }

                $outputFiles = [$outputFile];

                // Generate markdown report for deviations
                if ($extractor->getName() === 'custom_deviations' && !empty($data['deviations'])) {
                    $mdFile = 'quality_metrics/custom_deviations.md';
                    $writer->writeMarkdown($mdFile, $this->renderDeviationsMarkdown($data));
                    $outputFiles[] = $mdFile;
                }

                $allExtractedData[$extractor->getName()] = $data;

                // Collect any warnings emitted during extraction
                $warnings = $compilationContext->drainWarningsForExtractor($extractor->getName());

                $extractorResults[$extractor->getName()] = [
                    'status' => 'ok',
                    'item_count' => $itemCount,
                    'duration_ms' => $extractorDurationMs,
                    'view' => $viewDir,
                    'output_files' => $outputFiles,
                    'warnings' => $warnings,
                ];

                if (!empty($warnings)) {
                    $io->text("    ⚠ <comment>" . count($warnings) . " warning(s)</comment>");
                }

                $io->text("    ✓ <info>$itemCount items extracted</info> ({$extractorDurationMs}ms)");
            } catch (\Throwable $e) {
                $extractorDurationMs = round((microtime(true) - $extractorStart) * 1000, 1);
                $extractorResults[$extractor->getName()] = [
                    'status' => 'error',
                    'item_count' => 0,
                    'duration_ms' => $extractorDurationMs,
                    'view' => $extractor->getOutputView(),
                    'error' => $e->getMessage(),
                    'output_files' => [],
                ];

                $io->text("    ✗ <error>Error: {$e->getMessage()}</error>");
            }
        }

        // Write churn cache if both extractors ran fresh
        $churnCache = $compilationContext->getChurnCache();
        if ($churnCache !== null && $gitChurnExtractor !== null && !$gitChurnExtractor->wasCacheUsed()) {
            $fileChurn = $gitChurnExtractor->getFileChurn();
            $moduleChurn = $hotspotExtractor !== null ? $hotspotExtractor->getModuleChurn() : [];
            $churnCache->write(
                $config->getChurnWindowDays(),
                $scopes,
                $fileChurn,
                $moduleChurn
            );
        }

        return [$extractorResults, $allExtractedData];
    }

    /**
     * Generate schemas, AI digest, reverse indexes, and scenario bundles.
     */
    private function generatePostExtractorOutputs(
        array &$allExtractedData,
        array &$extractorResults,
        OutputWriter $writer,
        string $repoPath,
        TargetInterface $target,
        string $format,
        float $duration,
        SymfonyStyle $io
    ): void {
        // D.1: Generate JSON schemas
        $schemaGenerator = new SchemaGenerator();
        $schemaFiles = $schemaGenerator->generate($writer);
        $io->text(sprintf('    ✓ <info>%d JSON schemas generated</info>', count($schemaFiles)));

        // Generate AI digest
        $io->section('Generating AI digest');
        $digestGenerator = new AiDigestGenerator();
        $digestContent = $digestGenerator->generate(
            $allExtractedData,
            $extractorResults,
            $repoPath,
            $target->getName(),
            $duration
        );
        $writer->writeMarkdown('ai_digest.md', $digestContent);
        $io->text('    ✓ <info>ai_digest.md generated</info>');

        // C.3: Build reverse indexes from all extractor data
        $io->section('Building reverse indexes');
        $indexBuilder = new IndexBuilder();
        $reverseIndex = $indexBuilder->build($allExtractedData);
        $writer->writeJson('reverse_index/reverse_index.json', $reverseIndex);
        $allExtractedData['reverse_index'] = $reverseIndex;
        $extractorResults['reverse_index'] = [
            'status' => 'ok',
            'item_count' => $reverseIndex['summary']['indexed_classes']
                + $reverseIndex['summary']['indexed_modules']
                + $reverseIndex['summary']['indexed_events']
                + $reverseIndex['summary']['indexed_routes'],
            'view' => 'reverse_index',
            'output_files' => ['reverse_index/reverse_index.json'],
        ];
        $io->text(sprintf(
            '    ✓ <info>Reverse indexes built: %d classes, %d modules, %d events, %d routes</info>',
            $reverseIndex['summary']['indexed_classes'],
            $reverseIndex['summary']['indexed_modules'],
            $reverseIndex['summary']['indexed_events'],
            $reverseIndex['summary']['indexed_routes']
        ));

        // Generate scenario bundles
        $io->section('Generating scenario bundles');
        $scenarioGenerator = new ScenarioBundleGenerator();
        $scenarios = $scenarioGenerator->generate($allExtractedData);
        $scenarioCount = 0;
        foreach ($scenarios as $name => $bundle) {
            $safeFilename = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($name));
            $scenarioFile = 'scenarios/' . $safeFilename . '.' . $format;
            if ($format === 'jsonl') {
                $writer->writeJsonl($scenarioFile, [$bundle]);
            } else {
                $writer->writeJson($scenarioFile, $bundle);
            }
            $extractorResults['scenario_' . $safeFilename] = [
                'status' => 'ok',
                'item_count' => 1,
                'view' => 'scenarios',
                'output_files' => [$scenarioFile],
            ];
            $scenarioCount++;
        }
        $io->text("    ✓ <info>{$scenarioCount} scenario bundle(s) generated</info>");

        // P1: Write scenario coverage report
        $coverageReport = $scenarioGenerator->getCoverageReport();
        $writer->writeJson('scenarios/scenario_coverage.json', $coverageReport);
        $extractorResults['scenario_coverage'] = [
            'status' => 'ok',
            'item_count' => $coverageReport['total_scenarios'],
            'view' => 'scenarios',
            'output_files' => ['scenarios/scenario_coverage.json'],
        ];
        $io->text(sprintf(
            '    ✓ <info>Scenario coverage: %d/%d matched, %d unmatched</info>',
            $coverageReport['matched'],
            $coverageReport['total_scenarios'],
            $coverageReport['unmatched']
        ));
    }

    /**
     * B+.7: Run BundleValidator and record results.
     */
    private function runValidation(
        CompilerConfig $config,
        OutputWriter $writer,
        string $outDir,
        array &$extractorResults,
        array $allExtractedData,
        InputInterface $input,
        SymfonyStyle $io
    ): void {
        $io->section('Validating bundle');
        $validator = new BundleValidator($config, $writer, $outDir);
        $emittedEdgeTypes = $this->collectEmittedEdgeTypes($allExtractedData);
        $skipDeterminism = (bool) $input->getOption('skip-determinism-check');
        $validationResult = $validator->validate($extractorResults, $skipDeterminism, $emittedEdgeTypes, $allExtractedData);

        if (!$validationResult['passed']) {
            foreach ($validationResult['errors'] as $err) {
                $io->text("    ✗ <error>[{$err['rule']}] {$err['message']}</error>");
            }
        }
        foreach ($validationResult['warnings'] as $warn) {
            $io->text("    ⚠ <comment>[{$warn['rule']}] {$warn['message']}</comment>");
        }
        if ($validationResult['passed']) {
            $io->text("    ✓ <info>Bundle validation passed</info>");
        }

        // Include validation results in extractor results for manifest
        $extractorResults['_validation'] = [
            'status' => $validationResult['passed'] ? 'passed' : 'failed',
            'item_count' => count($validationResult['errors']) + count($validationResult['warnings']),
            'view' => '.',
            'output_files' => [],
            'validation' => $validationResult,
        ];
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
            if (is_array($items) && !ArrayUtil::isAssoc($items)) {
                foreach ($items as $item) {
                    $records[] = array_merge(['_section' => $section], is_array($item) ? $item : ['value' => $item]);
                }
            } else {
                $records[] = ['_section' => $section, 'data' => $items];
            }
        }
        return $records;
    }

    /**
     * Run CI threshold checks and write ci_summary.json.
     */
    private function runCiChecks(InputInterface $input, array $allData, OutputWriter $writer, SymfonyStyle $io): int
    {
        $violations = count($allData['layer_classification']['violations'] ?? []);
        $cycles = count($allData['architectural_debt']['cycles'] ?? []);
        $deviations = $allData['custom_deviations']['summary']['total_deviations'] ?? 0;
        $avgRisk = $allData['modifiability']['summary']['avg_risk_score'] ?? 0;
        $debtItems = $allData['architectural_debt']['summary']['total_debt_items'] ?? 0;
        $perfIndicators = $allData['performance']['summary']['total_indicators'] ?? 0;
        $highRiskModules = $allData['modifiability']['summary']['high_risk_modules'] ?? 0;

        $ciSummary = [
            'status' => 'pass',
            'metrics' => [
                'layer_violations' => $violations,
                'circular_dependencies' => $cycles,
                'deviations' => $deviations,
                'avg_modifiability_risk' => $avgRisk,
                'architectural_debt_items' => $debtItems,
                'performance_indicators' => $perfIndicators,
                'high_risk_modules' => $highRiskModules,
            ],
            'threshold_checks' => [],
        ];

        $failed = false;

        // Check thresholds
        $maxViolations = $input->getOption('max-violations');
        if ($maxViolations !== '' && $maxViolations !== null) {
            $limit = (int) $maxViolations;
            $passed = $violations <= $limit;
            $ciSummary['threshold_checks'][] = [
                'check' => 'max-violations',
                'limit' => $limit,
                'actual' => $violations,
                'passed' => $passed,
            ];
            if (!$passed) {
                $io->text("    ✗ <error>Layer violations ({$violations}) exceed threshold ({$limit})</error>");
                $failed = true;
            } else {
                $io->text("    ✓ <info>Layer violations ({$violations}) within threshold ({$limit})</info>");
            }
        }

        $maxCycles = $input->getOption('max-cycles');
        if ($maxCycles !== '' && $maxCycles !== null) {
            $limit = (int) $maxCycles;
            $passed = $cycles <= $limit;
            $ciSummary['threshold_checks'][] = [
                'check' => 'max-cycles',
                'limit' => $limit,
                'actual' => $cycles,
                'passed' => $passed,
            ];
            if (!$passed) {
                $io->text("    ✗ <error>Circular dependencies ({$cycles}) exceed threshold ({$limit})</error>");
                $failed = true;
            } else {
                $io->text("    ✓ <info>Circular dependencies ({$cycles}) within threshold ({$limit})</info>");
            }
        }

        $maxDeviations = $input->getOption('max-deviations');
        if ($maxDeviations !== '' && $maxDeviations !== null) {
            $limit = (int) $maxDeviations;
            $passed = $deviations <= $limit;
            $ciSummary['threshold_checks'][] = [
                'check' => 'max-deviations',
                'limit' => $limit,
                'actual' => $deviations,
                'passed' => $passed,
            ];
            if (!$passed) {
                $io->text("    ✗ <error>Deviations ({$deviations}) exceed threshold ({$limit})</error>");
                $failed = true;
            } else {
                $io->text("    ✓ <info>Deviations ({$deviations}) within threshold ({$limit})</info>");
            }
        }

        $maxRisk = $input->getOption('max-risk');
        if ($maxRisk !== '' && $maxRisk !== null) {
            $limit = (float) $maxRisk;
            $passed = $avgRisk <= $limit;
            $ciSummary['threshold_checks'][] = [
                'check' => 'max-risk',
                'limit' => $limit,
                'actual' => $avgRisk,
                'passed' => $passed,
            ];
            if (!$passed) {
                $io->text("    ✗ <error>Avg modifiability risk ({$avgRisk}) exceeds threshold ({$limit})</error>");
                $failed = true;
            } else {
                $io->text("    ✓ <info>Avg modifiability risk ({$avgRisk}) within threshold ({$limit})</info>");
            }
        }

        if ($failed) {
            $ciSummary['status'] = 'fail';
        }

        $writer->writeJson('ci_summary.json', $ciSummary);
        $io->text('    ✓ <info>ci_summary.json written</info>');

        if ($failed) {
            $io->newLine();
            $io->error('CI threshold check(s) failed.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function renderDeviationsMarkdown(array $data): string
    {
        $deviations = $data['deviations'] ?? [];
        $summary = $data['summary'] ?? [];

        $md = "# Custom Deviations Report\n\n";
        $md .= "Generated: " . date('Y-m-d H:i:s T') . "\n\n";

        // Summary
        $md .= "## Summary\n\n";
        $md .= "- **Total deviations:** " . ($summary['total_deviations'] ?? 0) . "\n";
        foreach ($summary['by_severity'] ?? [] as $severity => $count) {
            $md .= "- **{$severity}:** {$count}\n";
        }
        $md .= "\n";

        // Group by severity
        $grouped = [];
        foreach ($deviations as $d) {
            $grouped[$d['severity']][] = $d;
        }

        $severityLabels = [
            'critical' => '🔴 Critical',
            'high' => '🟠 High',
            'medium' => '🟡 Medium',
            'low' => '🟢 Low',
        ];

        foreach ($severityLabels as $severity => $label) {
            if (empty($grouped[$severity])) {
                continue;
            }

            $md .= "## {$label}\n\n";

            foreach ($grouped[$severity] as $d) {
                $md .= "### {$d['message']}\n\n";
                $md .= "- **Type:** `{$d['type']}`\n";
                $md .= "- **File:** `{$d['source_file']}`\n";

                if (!empty($d['recommendation'])) {
                    $md .= "- **Recommendation:** {$d['recommendation']}\n";
                }

                $md .= "\n";
            }
        }

        return $md;
    }

    /**
     * Count unique DI targets from extractor output for integrity score denominator.
     * Uses di_resolution_map resolutions count as proxy for total_di_targets.
     */
    private function countDiTargets(array $allExtractedData): int
    {
        $resolutions = $allExtractedData['di_resolution_map']['resolutions'] ?? [];
        return max(1, count($resolutions));
    }

    /**
     * B+.7: Collect all edge types actually emitted by extractors.
     * Used by BundleValidator to detect dead weight definitions.
     *
     * @return string[]
     */
    private function collectEmittedEdgeTypes(array $allExtractedData): array
    {
        $types = [];

        // Dependency graph edges
        foreach ($allExtractedData['dependency_graph']['edges'] ?? [] as $edge) {
            if (isset($edge['edge_type'])) {
                $types[$edge['edge_type']] = true;
            }
        }

        // Module graph edges
        foreach ($allExtractedData['module_graph']['edges'] ?? [] as $edge) {
            if (isset($edge['edge_type'])) {
                $types[$edge['edge_type']] = true;
            }
        }

        return array_keys($types);
    }
}

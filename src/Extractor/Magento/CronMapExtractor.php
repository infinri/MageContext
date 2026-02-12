<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec §3.2D: Cron map.
 *
 * Extracts cron job declarations from crontab.xml with:
 * - canonical cron_id, schedule, instance class, method
 * - declared_by module, group, evidence
 */
class CronMapExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'cron_map';
    }

    public function getDescription(): string
    {
        return 'Extracts cron job declarations from crontab.xml with evidence';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $cronJobs = [];

        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('crontab.xml')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseCrontabXml($file->getRealPath(), $fileId, $declaringModule);
                foreach ($parsed as $job) {
                    $cronJobs[] = $job;
                }
            }
        }

        // Sort by job name for determinism
        usort($cronJobs, fn($a, $b) => strcmp($a['job_name'], $b['job_name']));

        return [
            'cron_jobs' => $cronJobs,
            'summary' => [
                'total_cron_jobs' => count($cronJobs),
                'by_group' => $this->countByGroup($cronJobs),
                'by_module' => $this->countByModule($cronJobs),
            ],
        ];
    }

    private function parseCrontabXml(string $filePath, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            $this->warnInvalidXml($fileId, 'crontab.xml');
            return [];
        }

        $jobs = [];

        // <group id="default">
        //   <job name="job_name" instance="Class\Name" method="execute">
        //     <schedule>* * * * *</schedule>
        //     <config_path>crontab/default/jobs/job_name/schedule/cron_expr</config_path>
        //   </job>
        // </group>
        foreach ($xml->group ?? [] as $groupNode) {
            $groupId = (string) ($groupNode['id'] ?? 'default');

            foreach ($groupNode->job ?? [] as $jobNode) {
                $jobName = (string) ($jobNode['name'] ?? '');
                $instance = IdentityResolver::normalizeFqcn((string) ($jobNode['instance'] ?? ''));
                $method = (string) ($jobNode['method'] ?? 'execute');

                if ($jobName === '') {
                    continue;
                }

                $schedule = '';
                $configPath = '';
                if (isset($jobNode->schedule)) {
                    $schedule = trim((string) $jobNode->schedule);
                }
                if (isset($jobNode->config_path)) {
                    $configPath = trim((string) $jobNode->config_path);
                }

                $instanceModule = $instance !== '' ? $this->resolveModule($instance) : $declaringModule;

                $jobs[] = [
                    'cron_id' => "cron::{$jobName}",
                    'job_name' => $jobName,
                    'group' => $groupId,
                    'instance' => $instance,
                    'method' => $method,
                    'schedule' => $schedule,
                    'config_path' => $configPath,
                    'module' => $instanceModule,
                    'declared_by' => $declaringModule,
                    'evidence' => [
                        Evidence::fromXml(
                            $fileId,
                            "cron job={$jobName} instance={$instance} method={$method} schedule={$schedule}"
                        )->toArray(),
                    ],
                ];
            }
        }

        return $jobs;
    }

    private function countByGroup(array $cronJobs): array
    {
        $counts = [];
        foreach ($cronJobs as $job) {
            $group = $job['group'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    private function countByModule(array $cronJobs): array
    {
        $counts = [];
        foreach ($cronJobs as $job) {
            $mod = $job['declared_by'];
            $counts[$mod] = ($counts[$mod] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

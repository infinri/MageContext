<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento;

use MageContext\Extractor\AbstractExtractor;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use Symfony\Component\Finder\Finder;

/**
 * Spec §3.2D: CLI commands.
 *
 * Discovers Magento CLI commands by scanning di.xml for
 * Magento\Framework\Console\CommandListInterface argument items,
 * and by scanning PHP files extending Symfony Command in Console/Command directories.
 */
class CliCommandExtractor extends AbstractExtractor
{
    public function getName(): string
    {
        return 'cli_commands';
    }

    public function getDescription(): string
    {
        return 'Discovers CLI command declarations from di.xml and Console/Command directories';
    }

    public function getOutputView(): string
    {
        return 'runtime_view';
    }

    public function extract(string $repoPath, array $scopes): array
    {
        $commands = [];

        // 1. Scan di.xml for CommandListInterface argument items
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($scopePath)->name('di.xml')->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $parsed = $this->parseDiXmlCommands($file->getRealPath(), $fileId, $declaringModule);
                foreach ($parsed as $cmd) {
                    $commands[] = $cmd;
                }
            }
        }

        // 2. Scan Console/Command directories for PHP files
        foreach ($scopes as $scope) {
            $scopePath = $repoPath . '/' . trim($scope, '/');
            if (!is_dir($scopePath) || str_contains($scope, 'design')) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($scopePath)
                ->name('*.php')
                ->path('/Console\/Command\//')
                ->sortByName();

            foreach ($finder as $file) {
                $fileId = $this->fileId($file->getRealPath(), $repoPath);
                $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
                $cmd = $this->parseCommandPhp($file->getRealPath(), $fileId, $declaringModule);
                if ($cmd !== null) {
                    // Avoid duplicates from di.xml scan
                    $exists = false;
                    foreach ($commands as $existing) {
                        if ($existing['class'] === $cmd['class']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $commands[] = $cmd;
                    }
                }
            }
        }

        // Sort by command name for determinism
        usort($commands, fn($a, $b) => strcmp($a['command_name'] ?? '', $b['command_name'] ?? ''));

        return [
            'commands' => $commands,
            'summary' => [
                'total_commands' => count($commands),
                'by_module' => $this->countByModule($commands),
            ],
        ];
    }

    /**
     * Parse di.xml for CLI command registrations via CommandListInterface.
     */
    private function parseDiXmlCommands(string $filePath, string $fileId, string $declaringModule): array
    {
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return [];
        }

        $commands = [];

        // Look for type declarations targeting CommandListInterface or CommandList
        foreach ($xml->xpath('//type') ?: [] as $typeNode) {
            $typeName = (string) ($typeNode['name'] ?? '');
            if (!str_contains($typeName, 'CommandList')) {
                continue;
            }

            $argsNode = $typeNode->arguments ?? null;
            if ($argsNode === null) {
                continue;
            }

            foreach ($argsNode->argument ?? [] as $arg) {
                $argName = (string) ($arg['name'] ?? '');
                if ($argName !== 'commands') {
                    continue;
                }

                // Iterate over item elements inside the commands argument
                foreach ($arg->item ?? [] as $item) {
                    $itemName = (string) ($item['name'] ?? '');
                    $itemType = (string) ($item->attributes('xsi', true)->type ?? '');
                    $className = '';

                    if ($itemType === 'object') {
                        $className = IdentityResolver::normalizeFqcn(trim((string) $item));
                    }

                    if ($className !== '') {
                        $cmdModule = $this->resolveModule($className);
                        $commands[] = [
                            'command_name' => $itemName,
                            'class' => $className,
                            'module' => $cmdModule,
                            'declared_by' => $declaringModule,
                            'source' => 'di_xml',
                            'evidence' => [
                                Evidence::fromXml(
                                    $fileId,
                                    "CLI command '{$itemName}' class={$className}"
                                )->toArray(),
                            ],
                        ];
                    }
                }
            }
        }

        return $commands;
    }

    /**
     * Parse a PHP file in Console/Command to extract command name.
     */
    private function parseCommandPhp(string $filePath, string $fileId, string $declaringModule): ?array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Must extend Command or have configure() method
        if (!preg_match('/extends\s+Command\b/i', $content) && !str_contains($content, 'function configure')) {
            return null;
        }

        // Extract namespace + class
        $namespace = '';
        $className = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $namespace = $m[1];
        }
        if (preg_match('/class\s+(\w+)/', $content, $m)) {
            $className = $m[1];
        }

        if ($className === '') {
            return null;
        }

        $fqcn = $namespace !== '' ? $namespace . '\\' . $className : $className;

        // Try to extract command name from setName() or NAME constant
        $commandName = '';
        if (preg_match('/->setName\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $content, $m)) {
            $commandName = $m[1];
        } elseif (preg_match('/const\s+NAME\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $commandName = $m[1];
        } elseif (preg_match('/static::\$defaultName\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $commandName = $m[1];
        }

        $cmdModule = $this->resolveModule($fqcn);

        return [
            'command_name' => $commandName,
            'class' => IdentityResolver::normalizeFqcn($fqcn),
            'module' => $cmdModule,
            'declared_by' => $declaringModule,
            'source' => 'php_scan',
            'evidence' => [
                Evidence::fromPhpAst(
                    $fileId,
                    0,
                    null,
                    "CLI command class {$fqcn}" . ($commandName !== '' ? " name='{$commandName}'" : '')
                )->toArray(),
            ],
        ];
    }

    private function countByModule(array $commands): array
    {
        $counts = [];
        foreach ($commands as $cmd) {
            $mod = $cmd['declared_by'];
            $counts[$mod] = ($counts[$mod] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }
}

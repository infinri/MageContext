<?php

declare(strict_types=1);

namespace MageContext\Extractor\Magento\RuntimeConfig;

use MageContext\Extractor\CompilationContext;
use MageContext\Identity\Evidence;
use MageContext\Identity\IdentityResolver;
use MageContext\Identity\WarningCollector;
use Symfony\Component\Finder\Finder;

/**
 * Shared helper context passed to all runtime config collectors.
 *
 * Provides module resolution, warning emission, file ID generation,
 * and XML scanning without coupling collectors to AbstractExtractor.
 */
class CollectorContext
{
    public function __construct(
        private readonly string $repoPath,
        private readonly array $scopes,
        private readonly CompilationContext $compilationContext,
        private readonly string $extractorName,
    ) {
    }

    public function repoPath(): string
    {
        return $this->repoPath;
    }

    public function scopes(): array
    {
        return $this->scopes;
    }

    public function fileId(string $absolutePath): string
    {
        return IdentityResolver::fileId($absolutePath, $this->repoPath);
    }

    public function resolveModuleFromFile(string $absolutePath): string
    {
        return $this->compilationContext->getModuleResolver()->resolveAbsolutePath($absolutePath);
    }

    public function warn(string $category, string $message): void
    {
        $this->compilationContext->addWarning($category, $message, $this->extractorName);
    }

    public function warnInvalidXml(string $file, string $context = ''): void
    {
        $msg = "Invalid XML: {$file}";
        if ($context !== '') {
            $msg .= " ({$context})";
        }
        $this->warn(WarningCollector::CAT_INVALID_XML, $msg);
    }

    public function warnGeneral(string $message): void
    {
        $this->warn(WarningCollector::CAT_GENERAL, $message);
    }

    /**
     * Iterate valid scope paths as absolute directories.
     *
     * @return \Generator<string>
     */
    public function scopePaths(): \Generator
    {
        foreach ($this->scopes as $scope) {
            $scopePath = $this->repoPath . '/' . trim($scope, '/');
            if (is_dir($scopePath)) {
                yield $scopePath;
            }
        }
    }

    /**
     * Find XML files by name within a scope path and invoke a callback for each parsed file.
     *
     * Callback signature: function(SimpleXMLElement $xml, string $fileId, string $module): void
     */
    public function findAndParseXml(string $scopePath, string $fileName, callable $callback): void
    {
        $finder = new Finder();
        $finder->files()->in($scopePath)->name($fileName)->sortByName();

        foreach ($finder as $file) {
            $xml = @simplexml_load_file($file->getRealPath());
            if ($xml === false) {
                $this->warnInvalidXml($this->fileId($file->getRealPath()), $fileName);
                continue;
            }

            $fileId = $this->fileId($file->getRealPath());
            $declaringModule = $this->resolveModuleFromFile($file->getRealPath());
            $callback($xml, $fileId, $declaringModule);
        }
    }
}

<?php

declare(strict_types=1);

namespace MageContext\Tests\Support;

/**
 * Shared helper for test classes that need temporary directories.
 */
trait TempDirectoryTrait
{
    private string $tmpDir;

    private function createTmpDir(string $prefix): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/magecontext-' . $prefix . '-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

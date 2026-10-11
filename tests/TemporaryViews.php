<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

/**
 * Writes test templates to a temporary directory and removes it after each test.
 */
trait TemporaryViews
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function views(array $files): string
    {
        $dir = sys_get_temp_dir() . '/kaly-tpl-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;

        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, recursive: true) && !is_dir($parent)) {
                throw new \RuntimeException(sprintf('Unable to create test directory "%s".', $parent));
            }

            if (file_put_contents($path, $contents) === false) {
                throw new \RuntimeException(sprintf('Unable to write test template "%s".', $path));
            }
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                $this->removeEmptyDirectory($path);
                continue;
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
            unlink($path);
        }

        $this->removeEmptyDirectory($dir);
    }

    /**
     * On Windows, a deleted file stays listed as "delete pending" while another process
     * (antivirus, indexer) still holds it open, so wait for the directory to be empty.
     */
    private function removeEmptyDirectory(string $dir): void
    {
        for ($attempt = 0; $attempt < 20 && (new \FilesystemIterator($dir))->valid(); $attempt++) {
            usleep(25_000);
        }

        rmdir($dir);
    }
}

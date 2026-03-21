<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Blade;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use PHPUnit\Framework\TestCase;

final class BladeRuntimeCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        BladeRuntimeCache::reset();
        parent::tearDown();
    }

    public function test_blade_files_are_cached_for_the_lifetime_of_the_cache_instance(): void
    {
        $root = $this->tempDirectory();

        try {
            $firstFile = $root . '/resources/views/first.blade.php';
            $secondFile = $root . '/resources/views/second.blade.php';
            @mkdir(dirname($firstFile), 0777, true);
            file_put_contents($firstFile, '<div>first</div>');

            $cache = new BladeRuntimeCache();
            $resolvedFirstFile = realpath($firstFile) ?: $firstFile;

            $firstScan = $cache->bladeFiles($root);
            file_put_contents($secondFile, '<div>second</div>');
            $secondScan = $cache->bladeFiles($root);

            self::assertSame([$resolvedFirstFile], $firstScan);
            self::assertSame([$resolvedFirstFile], $secondScan);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_view_files_are_cached_for_the_lifetime_of_the_cache_instance(): void
    {
        $root = $this->tempDirectory();

        try {
            $viewsRoot = $root . '/resources/views';
            $firstFile = $viewsRoot . '/first.php';
            $secondFile = $viewsRoot . '/second.html';
            @mkdir($viewsRoot, 0777, true);
            file_put_contents($firstFile, '<div>first</div>');

            $cache = new BladeRuntimeCache();
            $resolvedFirstFile = realpath($firstFile) ?: $firstFile;

            $firstScan = $cache->viewFiles($viewsRoot);
            file_put_contents($secondFile, '<div>second</div>');
            $secondScan = $cache->viewFiles($viewsRoot);

            self::assertSame([$resolvedFirstFile], $firstScan);
            self::assertSame([$resolvedFirstFile], $secondScan);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_file_contents_are_cached_for_the_lifetime_of_the_cache_instance(): void
    {
        $root = $this->tempDirectory();

        try {
            $file = $root . '/resources/views/example.blade.php';
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, '<div>first</div>');

            $cache = new BladeRuntimeCache();

            self::assertSame('<div>first</div>', $cache->contents($file));

            file_put_contents($file, '<div>second</div>');

            self::assertSame('<div>first</div>', $cache->contents($file));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_shared_cache_is_reused_until_it_is_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $file = $root . '/resources/views/example.blade.php';
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, '<div>first</div>');

            self::assertSame('<div>first</div>', BladeRuntimeCache::shared()->contents($file));

            file_put_contents($file, '<div>second</div>');

            self::assertSame('<div>first</div>', BladeRuntimeCache::shared()->contents($file));

            BladeRuntimeCache::reset();

            self::assertSame('<div>second</div>', BladeRuntimeCache::shared()->contents($file));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/blade-cache-' . bin2hex(random_bytes(8));
        @mkdir($root, 0777, true);

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}

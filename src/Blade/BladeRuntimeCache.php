<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

use function array_key_exists;
use function array_values;
use function file_get_contents;
use function is_file;
use function is_string;
use function preg_match;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;

/**
 * Run-local cache shared by Blade-heavy enrichers so one indexing invocation only
 * walks and rereads the same Blade/view files once.
 */
class BladeRuntimeCache
{
    private static ?self $shared = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $store = [];

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function reset(): void
    {
        self::$shared = null;
    }

    /**
     * @template T
     * @param callable():T $resolver
     * @return T
     */
    public function remember(string $bucket, string $key, callable $resolver): mixed
    {
        if (array_key_exists($key, $this->store[$bucket] ?? [])) {
            return $this->store[$bucket][$key];
        }

        return $this->store[$bucket][$key] = $resolver();
    }

    public function contents(string $path): ?string
    {
        $resolvedPath = realpath($path) ?: $path;

        return $this->remember('contents', $resolvedPath, static function () use ($resolvedPath): ?string {
            $contents = file_get_contents($resolvedPath);

            return is_string($contents) ? $contents : null;
        });
    }

    /**
     * @return list<string>
     */
    public function bladeFiles(string $projectRoot, bool $includeVendor = false): array
    {
        return $this->files($projectRoot, 'blade-files', '/\.blade\.php$/i', $includeVendor);
    }

    /**
     * @return list<string>
     */
    public function viewFiles(string $root, bool $includeVendor = false): array
    {
        return $this->files($root, 'view-files', '/\.(?:blade\.php|php|html)$/i', $includeVendor);
    }

    /**
     * @return list<string>
     */
    private function files(string $root, string $bucket, string $pattern, bool $includeVendor): array
    {
        $resolvedRoot = realpath($root) ?: $root;
        $cacheKey = $resolvedRoot . "\x1F" . ($includeVendor ? '1' : '0');

        return $this->remember($bucket, $cacheKey, static function () use (
            $resolvedRoot,
            $pattern,
            $includeVendor,
        ): array {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $resolvedRoot,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));
            $files = [];

            foreach (new RegexIterator($iterator, $pattern) as $file) {
                $path = $file->getPathname();

                if (
                    !is_file($path)
                    || preg_match($pattern, $path) !== 1
                    || !$includeVendor && str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
                    || str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
                    || str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
                    || str_contains(
                        $path,
                        DIRECTORY_SEPARATOR
                        . 'storage'
                        . DIRECTORY_SEPARATOR
                        . 'framework'
                        . DIRECTORY_SEPARATOR
                        . 'views'
                        . DIRECTORY_SEPARATOR,
                    )
                ) {
                    continue;
                }

                $files[] = $path;
            }

            sort($files);

            return array_values($files);
        });
    }
}

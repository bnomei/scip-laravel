<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Throwable;

use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_dir;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function spl_object_id;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class PrefixedAnonymousComponentInventoryBuilder
{
    /**
     * @var array<string, PrefixedAnonymousComponentInventory>
     */
    private static array $inventoryCache = [];

    public static function reset(): void
    {
        self::$inventoryCache = [];
    }

    public function __construct(?BladeRuntimeCache $bladeCache = null)
    {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    private readonly BladeRuntimeCache $bladeCache;

    public function collect(LaravelContext $context): PrefixedAnonymousComponentInventory
    {
        $cacheKey = $this->cacheKey($context);

        if (isset(self::$inventoryCache[$cacheKey])) {
            return self::$inventoryCache[$cacheKey];
        }

        $compiler = $this->bladeCompiler($context);

        if ($compiler === null || !method_exists($compiler, 'getAnonymousComponentPaths')) {
            return self::$inventoryCache[$cacheKey] = new PrefixedAnonymousComponentInventory([], [], []);
        }

        $registrations = $compiler->getAnonymousComponentPaths();

        if (!is_array($registrations) || $registrations === []) {
            return self::$inventoryCache[$cacheKey] = new PrefixedAnonymousComponentInventory([], [], []);
        }

        $prefixes = [];
        $localCandidatesByTag = [];
        $externalPackagesByTag = [];

        foreach ($registrations as $registration) {
            if (!is_array($registration)) {
                continue;
            }

            $prefix = $registration['prefix'] ?? null;
            $path = $registration['path'] ?? null;

            if (!is_string($prefix) || $prefix === '' || !is_string($path) || !is_dir($path)) {
                continue;
            }

            $prefixes[$prefix] = true;
            $isLocal = $this->isLocalProjectPath($context, $path);
            $packageName = $this->vendorPackageName($path);

            foreach ($this->bladeCache->viewFiles($path, includeVendor: true) as $filePath) {
                $viewName = $this->localViewName($prefix, $path, $filePath);

                if ($viewName === null) {
                    continue;
                }

                foreach ($this->tagKeys($prefix, $path, $filePath) as $tagKey) {
                    if (!$isLocal) {
                        if (is_string($packageName) && $packageName !== '') {
                            $externalPackagesByTag[$tagKey][$packageName] = true;
                        }

                        continue;
                    }

                    $localCandidatesByTag[$tagKey][$viewName] = true;
                }
            }
        }

        ksort($prefixes);
        ksort($localCandidatesByTag);
        $resolvedViewNamesByTag = [];

        foreach ($localCandidatesByTag as $tagKey => $viewNames) {
            if (count($viewNames) !== 1) {
                continue;
            }

            $resolvedViewNamesByTag[$tagKey] = array_keys($viewNames)[0];
        }

        ksort($resolvedViewNamesByTag);
        ksort($externalPackagesByTag);
        $resolvedExternalPackagesByTag = [];

        foreach ($externalPackagesByTag as $tagKey => $packageNames) {
            if (isset($resolvedViewNamesByTag[$tagKey]) || count($packageNames) !== 1) {
                continue;
            }

            $resolvedExternalPackagesByTag[$tagKey] = array_keys($packageNames)[0];
        }

        ksort($resolvedExternalPackagesByTag);

        return self::$inventoryCache[$cacheKey] = new PrefixedAnonymousComponentInventory(
            prefixes: array_keys($prefixes),
            resolvedViewNamesByTag: $resolvedViewNamesByTag,
            externalPackagesByTag: $resolvedExternalPackagesByTag,
        );
    }

    private function cacheKey(LaravelContext $context): string
    {
        return $context->projectRoot . "\x1F" . spl_object_id($context->application);
    }

    private function bladeCompiler(LaravelContext $context): ?object
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $compiler = $context->application->make('blade.compiler');
        } catch (Throwable) {
            return null;
        }

        return is_object($compiler) ? $compiler : null;
    }

    private function localViewName(string $prefix, string $root, string $filePath): ?string
    {
        $componentPath = $this->componentPath($root, $filePath);

        if ($componentPath === null) {
            return null;
        }

        return $prefix . '.' . $componentPath;
    }

    /**
     * @return list<string>
     */
    private function tagKeys(string $prefix, string $root, string $filePath): array
    {
        $componentPath = $this->componentPath($root, $filePath);

        if ($componentPath === null) {
            return [];
        }

        $segments = explode('.', $componentPath);
        $tags = [$prefix . ':' . $componentPath];

        if (count($segments) >= 2 && $segments[array_key_last($segments)] === 'index') {
            $tags[] = $prefix . ':' . implode('.', array_slice($segments, 0, -1));
        }

        if (count($segments) >= 2 && $segments[array_key_last($segments)] === $segments[count($segments) - 2]) {
            $tags[] = $prefix . ':' . implode('.', array_slice($segments, 0, -1));
        }

        $tags = array_values(array_unique(array_filter(
            $tags,
            static fn(mixed $value): bool => is_string($value) && !str_ends_with($value, ':'),
        )));
        sort($tags);

        return $tags;
    }

    private function componentPath(string $root, string $filePath): ?string
    {
        $resolvedRoot = realpath($root) ?: $root;
        $resolvedPath = realpath($filePath) ?: $filePath;
        $relativePath = substr($resolvedPath, strlen($resolvedRoot) + 1);
        $componentPath = str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);

        foreach (['.blade.php', '.php', '.html'] as $suffix) {
            if (str_ends_with($componentPath, $suffix)) {
                $componentPath = substr($componentPath, 0, -strlen($suffix));
                break;
            }
        }

        return $componentPath !== '' ? $componentPath : null;
    }

    private function isLocalProjectPath(LaravelContext $context, string $path): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedRoot = realpath($context->projectRoot) ?: $context->projectRoot;
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return (
            ($resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, $rootPrefix))
            && !str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
        );
    }

    private function vendorPackageName(string $path): ?string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        if (preg_match('#/vendor/([^/]+)/([^/]+)(?:/|$)#', $normalizedPath, $matches) !== 1) {
            return null;
        }

        $vendor = $matches[1] ?? null;
        $package = $matches[2] ?? null;

        return is_string($vendor) && $vendor !== '' && is_string($package) && $package !== ''
            ? $vendor . '/' . $package
            : null;
    }
}

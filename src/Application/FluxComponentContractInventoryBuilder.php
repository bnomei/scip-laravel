<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;

use function array_filter;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_dir;
use function is_string;
use function ksort;
use function preg_match_all;
use function realpath;
use function sort;
use function spl_object_id;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;

final class FluxComponentContractInventoryBuilder
{
    /**
     * @var array<string, FluxComponentContractInventory>
     */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    public function __construct(?BladeRuntimeCache $bladeCache = null)
    {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    private readonly BladeRuntimeCache $bladeCache;

    public function collect(LaravelContext $context): FluxComponentContractInventory
    {
        $cacheKey = $context->projectRoot . "\x1F" . spl_object_id($context->application);

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $localDocumentationByViewName = [];
        $localTags = [];
        $localRoot = $context->projectPath('resources', 'views', 'flux');

        if (is_dir($localRoot)) {
            foreach ($this->bladeCache->bladeFiles($localRoot) as $filePath) {
                $componentPath = $this->componentPath($localRoot, $filePath);

                if ($componentPath === null) {
                    continue;
                }

                $viewName = 'flux.' . $componentPath;

                foreach ($this->tagsForComponentPath($componentPath) as $tag) {
                    $localTags[$tag] = true;
                }

                $documentation = $this->documentationForTemplate($filePath);

                if ($documentation !== []) {
                    $localDocumentationByViewName[$viewName] = $documentation;
                }
            }
        }

        $externalDocumentationByTag = [];

        foreach ($this->vendorFluxRoots($context->projectRoot) as $root) {
            foreach ($this->bladeCache->bladeFiles($root, includeVendor: true) as $filePath) {
                $componentPath = $this->componentPath($root, $filePath);

                if ($componentPath === null) {
                    continue;
                }

                $documentation = $this->documentationForTemplate($filePath);

                if ($documentation === []) {
                    continue;
                }

                foreach ($this->tagsForComponentPath($componentPath) as $tag) {
                    if (isset($localTags[$tag])) {
                        continue;
                    }

                    $externalDocumentationByTag[$tag] = $documentation;
                }
            }
        }

        ksort($localDocumentationByViewName);
        ksort($externalDocumentationByTag);

        return self::$cache[$cacheKey] = new FluxComponentContractInventory(
            localDocumentationByViewName: $localDocumentationByViewName,
            externalDocumentationByTag: $externalDocumentationByTag,
        );
    }

    /**
     * @return list<string>
     */
    private function vendorFluxRoots(string $projectRoot): array
    {
        $roots = [];
        $path = $projectRoot . '/vendor/livewire/flux/stubs/resources/views/flux';
        $resolved = realpath($path) ?: $path;

        if (is_dir($resolved)) {
            $roots[] = $resolved;
        }

        sort($roots);

        return $roots;
    }

    private function componentPath(string $root, string $filePath): ?string
    {
        $resolvedRoot = realpath($root) ?: $root;
        $resolvedPath = realpath($filePath) ?: $filePath;
        $relativePath = substr($resolvedPath, strlen($resolvedRoot) + 1);
        $componentPath = str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);

        if (str_ends_with($componentPath, '.blade.php')) {
            $componentPath = substr($componentPath, 0, -10);
        }

        return $componentPath !== '' ? $componentPath : null;
    }

    /**
     * @return list<string>
     */
    private function tagsForComponentPath(string $componentPath): array
    {
        $segments = explode('.', $componentPath);
        $tags = ['flux:' . $componentPath];

        if (count($segments) >= 2 && $segments[array_key_last($segments)] === 'index') {
            $tags[] = 'flux:' . implode('.', array_slice($segments, 0, -1));
        }

        if (count($segments) >= 2 && $segments[array_key_last($segments)] === $segments[count($segments) - 2]) {
            $tags[] = 'flux:' . implode('.', array_slice($segments, 0, -1));
        }

        $tags = array_values(array_unique(array_filter(
            $tags,
            static fn(string $tag): bool => !str_ends_with($tag, ':'),
        )));
        sort($tags);

        return $tags;
    }

    /**
     * @return list<string>
     */
    private function documentationForTemplate(string $filePath): array
    {
        $contents = $this->bladeCache->contents($filePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $documentation = [];
        $props = $this->propsForTemplate($contents);

        if ($props !== []) {
            $documentation[] = 'Flux props: ' . implode(', ', $props);
        }

        $slots = $this->slotNamesForTemplate($contents);

        if ($slots !== []) {
            $documentation[] = 'Flux slots: ' . implode(', ', $slots);
        }

        $attributes = $this->dataAttributesForTemplate($contents);

        if ($attributes !== []) {
            $documentation[] = 'Flux data attributes: ' . implode(', ', $attributes);
        }

        sort($documentation);

        return array_values(array_unique($documentation));
    }

    /**
     * @return list<string>
     */
    private function propsForTemplate(string $contents): array
    {
        if (preg_match_all('/[\'"](?<name>[A-Za-z_][A-Za-z0-9_-]*)[\'"]\s*(?:=>|,|\])/', $contents, $matches) < 1) {
            return [];
        }

        $props = array_values(array_unique(array_filter(
            $matches['name'] ?? [],
            static fn(mixed $name): bool => is_string($name) && $name !== '',
        )));
        sort($props);

        return $props;
    }

    /**
     * @return list<string>
     */
    private function slotNamesForTemplate(string $contents): array
    {
        if (preg_match_all('/data-slot="(?<slot>[A-Za-z0-9_.:-]+)"/', $contents, $matches) < 1) {
            return [];
        }

        $slots = array_values(array_unique(array_filter(
            $matches['slot'] ?? [],
            static fn(mixed $slot): bool => is_string($slot) && $slot !== '',
        )));
        sort($slots);

        return $slots;
    }

    /**
     * @return list<string>
     */
    private function dataAttributesForTemplate(string $contents): array
    {
        if (preg_match_all('/\b(data-flux-[A-Za-z0-9_-]+|data-slot)\b/', $contents, $matches) < 1) {
            return [];
        }

        $attributes = array_values(array_unique(array_filter(
            $matches[1] ?? [],
            static fn(mixed $attribute): bool => is_string($attribute) && $attribute !== '',
        )));
        sort($attributes);

        return $attributes;
    }
}

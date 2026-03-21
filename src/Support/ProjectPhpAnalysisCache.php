<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

use function array_key_exists;
use function array_unique;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_encode;
use function sort;
use function str_contains;
use function str_ends_with;

final class ProjectPhpAnalysisCache
{
    private static ?self $shared = null;

    /**
     * @var array<string, list<string>>
     */
    private static array $fileListCache = [];

    /**
     * @var array<string, ?string>
     */
    private static array $contentsCache = [];

    /**
     * @var array<string, ?list<Node>>
     */
    private static array $resolvedAstCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $memoizedStore = [];

    private static ?Parser $parser = null;

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function reset(): void
    {
        self::$shared = null;
        self::$fileListCache = [];
        self::$contentsCache = [];
        self::$resolvedAstCache = [];
        self::$memoizedStore = [];
    }

    /**
     * @template T
     * @param callable():T $resolver
     * @return T
     */
    public function remember(string $bucket, string $key, callable $resolver): mixed
    {
        if (array_key_exists($key, self::$memoizedStore[$bucket] ?? [])) {
            return self::$memoizedStore[$bucket][$key];
        }

        return self::$memoizedStore[$bucket][$key] = $resolver();
    }

    /**
     * @return list<string>
     */
    public function projectPhpFiles(string $projectRoot): array
    {
        return $this->phpFilesInRoots(roots: [$projectRoot], excludedPathFragments: [
            DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR
                . 'storage'
                . DIRECTORY_SEPARATOR
                . 'framework'
                . DIRECTORY_SEPARATOR
                . 'views'
                . DIRECTORY_SEPARATOR,
        ]);
    }

    /**
     * @param list<string> $roots
     * @param list<string> $excludedPathFragments
     * @return list<string>
     */
    public function phpFilesInRoots(array $roots, array $excludedPathFragments = []): array
    {
        sort($roots);
        sort($excludedPathFragments);
        $cacheKey = (string) json_encode([$roots, $excludedPathFragments]);

        if (isset(self::$fileListCache[$cacheKey])) {
            return self::$fileListCache[$cacheKey];
        }

        $files = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach (new RegexIterator($iterator, '/\.php$/i') as $file) {
                $path = $file->getPathname();

                if (!is_file($path) || !str_ends_with($path, '.php')) {
                    continue;
                }

                if ($this->isExcluded($path, $excludedPathFragments)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return self::$fileListCache[$cacheKey] = $files;
    }

    public function contents(string $filePath): ?string
    {
        if (array_key_exists($filePath, self::$contentsCache)) {
            return self::$contentsCache[$filePath];
        }

        $contents = file_get_contents($filePath);

        return self::$contentsCache[$filePath] = is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @return ?list<Node>
     */
    public function resolvedAst(string $filePath): ?array
    {
        if (array_key_exists($filePath, self::$resolvedAstCache)) {
            return self::$resolvedAstCache[$filePath];
        }

        $contents = $this->contents($filePath);

        if ($contents === null) {
            return self::$resolvedAstCache[$filePath] = null;
        }

        try {
            $ast = self::parser()->parse($contents);
        } catch (\PhpParser\Error) {
            return self::$resolvedAstCache[$filePath] = null;
        }

        if (!is_array($ast)) {
            return self::$resolvedAstCache[$filePath] = null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
        $resolved = $traverser->traverse($ast);

        return self::$resolvedAstCache[$filePath] = is_array($resolved) ? $resolved : null;
    }

    private static function parser(): Parser
    {
        return self::$parser ??= (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param list<string> $excludedPathFragments
     */
    private function isExcluded(string $path, array $excludedPathFragments): bool
    {
        foreach ($excludedPathFragments as $fragment) {
            if ($fragment !== '' && str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

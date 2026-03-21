<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

use function is_string;

final class PhpReturnedArrayKeyExtractor
{
    private NodeFinder $nodeFinder;

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    /**
     * @return list<PhpReturnedArrayKey>
     */
    public function extract(string $filePath, string $prefix = '', bool $includeArrayContainers = true): array
    {
        $cacheKey = $filePath . "\x1F" . $prefix . "\x1F" . ($includeArrayContainers ? '1' : '0');

        return $this->analysisCache->remember('php-returned-array-keys', $cacheKey, function () use (
            $filePath,
            $prefix,
            $includeArrayContainers,
        ): array {
            $contents = $this->analysisCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                return [];
            }

            $ast = $this->analysisCache->resolvedAst($filePath);

            if ($ast === null) {
                return [];
            }

            $return = $this->nodeFinder->findFirstInstanceOf($ast, Return_::class);

            if (!$return instanceof Return_ || !$return->expr instanceof Array_) {
                return [];
            }

            $definitions = [];
            $this->collect($filePath, $contents, $return->expr, $prefix, $includeArrayContainers, $definitions);

            return $definitions;
        });
    }

    /**
     * @param list<PhpReturnedArrayKey> $definitions
     */
    private function collect(
        string $filePath,
        string $contents,
        Array_ $array,
        string $prefix,
        bool $includeArrayContainers,
        array &$definitions,
    ): void {
        foreach ($array->items as $item) {
            if ($item === null || !$item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $segment = $item->key->value;

            if ($segment === '') {
                continue;
            }

            $fullKey = $prefix === '' ? $segment : $prefix . '.' . $segment;

            if (!$item->value instanceof Array_ || $includeArrayContainers) {
                $start = $item->key->getStartFilePos();
                $end = $item->key->getEndFilePos();

                if ($start >= 0 && $end >= 0) {
                    $definitions[] = new PhpReturnedArrayKey(
                        filePath: $filePath,
                        key: $fullKey,
                        range: SourceRange::fromOffsets($contents, $start + 1, $end),
                    );
                }
            }

            if ($item->value instanceof Array_) {
                $this->collect($filePath, $contents, $item->value, $fullKey, $includeArrayContainers, $definitions);
            }
        }
    }
}

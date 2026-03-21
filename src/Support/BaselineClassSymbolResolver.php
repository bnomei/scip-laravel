<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;

final class BaselineClassSymbolResolver
{
    public function resolve(Index $baselineIndex, string $relativePath, string $className, int $lineNumber): ?string
    {
        return BaselineLookupCache::for($baselineIndex)->resolveClassSymbol($relativePath, $className, $lineNumber);
    }
}

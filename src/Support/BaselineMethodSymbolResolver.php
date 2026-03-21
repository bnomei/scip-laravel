<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;

final class BaselineMethodSymbolResolver
{
    public function resolve(Index $baselineIndex, string $relativePath, string $methodName, int $lineNumber): ?string
    {
        return BaselineLookupCache::for($baselineIndex)->resolveMethodSymbol($relativePath, $methodName, $lineNumber);
    }
}

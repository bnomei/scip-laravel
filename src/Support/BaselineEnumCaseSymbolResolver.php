<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;

final class BaselineEnumCaseSymbolResolver
{
    public function resolve(Index $baselineIndex, string $relativePath, string $className, string $caseName): ?string
    {
        return BaselineLookupCache::for($baselineIndex)->resolveEnumCaseSymbol($relativePath, $className, $caseName);
    }
}

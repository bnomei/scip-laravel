<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;

final class BaselineConstantSymbolResolver
{
    public function resolve(
        Index $baselineIndex,
        string $relativePath,
        string $className,
        string $constantName,
    ): ?string {
        return BaselineLookupCache::for($baselineIndex)->resolveConstantSymbol(
            $relativePath,
            $className,
            $constantName,
        );
    }
}

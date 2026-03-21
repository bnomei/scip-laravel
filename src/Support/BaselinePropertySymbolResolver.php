<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;

final class BaselinePropertySymbolResolver
{
    public function resolve(
        Index $baselineIndex,
        string $relativePath,
        string $className,
        string $propertyName,
    ): ?string {
        return BaselineLookupCache::for($baselineIndex)->resolvePropertySymbol(
            $relativePath,
            $className,
            $propertyName,
        );
    }
}

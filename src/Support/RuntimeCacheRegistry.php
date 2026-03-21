<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;

final class RuntimeCacheRegistry
{
    public static function reset(): void
    {
        BladeRuntimeCache::reset();
        ProjectPhpAnalysisCache::reset();
        \Bnomei\ScipLaravel\Application\BladeClassComponentInventoryBuilder::reset();
        \Bnomei\ScipLaravel\Application\FluxComponentContractInventoryBuilder::reset();
        \Bnomei\ScipLaravel\Application\LivewireComponentInventoryBuilder::reset();
        \Bnomei\ScipLaravel\Application\PrefixedAnonymousComponentInventoryBuilder::reset();
        RouteParameterContractInventoryBuilder::reset();
        PhpLiteralCallFinder::reset();
        PhpLiteralInstantiationFinder::reset();
        PhpRouteDeclarationFinder::reset();
        RouteDepthExtractor::reset();
        PolicyClassResolver::reset();
        ProjectFallbackSymbolResolver::reset();
        \Bnomei\ScipLaravel\Application\RouteBoundModelScopeInventoryBuilder::reset();
    }
}

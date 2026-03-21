<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class PrefixedAnonymousComponentInventory
{
    /**
     * @param list<string> $prefixes
     * @param array<string, string> $resolvedViewNamesByTag
     * @param array<string, string> $externalPackagesByTag
     */
    public function __construct(
        public array $prefixes,
        public array $resolvedViewNamesByTag,
        public array $externalPackagesByTag = [],
    ) {}
}

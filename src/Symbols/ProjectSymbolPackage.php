<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Symbols;

final readonly class ProjectSymbolPackage
{
    public function __construct(
        public string $manager,
        public string $name,
        public string $version,
    ) {}
}

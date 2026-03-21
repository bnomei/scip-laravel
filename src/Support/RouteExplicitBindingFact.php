<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class RouteExplicitBindingFact
{
    public function __construct(
        public string $filePath,
        public string $parameter,
        public string $className,
        public SourceRange $range,
    ) {}
}

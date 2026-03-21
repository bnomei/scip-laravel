<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class RouteGuardReference
{
    public function __construct(
        public string $filePath,
        public string $routeName,
        public string $kind,
        public string $literal,
        public SourceRange $range,
        public string $mode = 'applied',
    ) {}
}

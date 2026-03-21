<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLocalSymbolReference
{
    public function __construct(
        public string $symbol,
        public SourceRange $range,
    ) {}
}

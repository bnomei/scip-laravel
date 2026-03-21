<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeDirectiveReference
{
    public function __construct(
        public string $directive,
        public string $type,
        public string $literal,
        public SourceRange $range,
    ) {}
}

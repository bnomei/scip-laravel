<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLiteralReference
{
    public function __construct(
        public string $domain,
        public string $directive,
        public string $literal,
        public SourceRange $range,
    ) {}
}

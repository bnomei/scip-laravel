<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeUnsupportedSite
{
    public function __construct(
        public SourceRange $range,
        public string $code,
        public string $message,
        public int $syntaxKind,
    ) {}
}

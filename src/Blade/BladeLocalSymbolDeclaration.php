<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLocalSymbolDeclaration
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $symbol,
        public SourceRange $range,
        public SourceRange $enclosingRange,
    ) {}
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLayoutContractReference
{
    public function __construct(
        public string $family,
        public string $kind,
        public string $name,
        public SourceRange $range,
    ) {}
}

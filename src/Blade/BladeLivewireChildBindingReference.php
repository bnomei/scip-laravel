<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLivewireChildBindingReference
{
    public function __construct(
        public string $childAlias,
        public string $kind,
        public string $parentProperty,
        public SourceRange $parentRange,
        public ?string $childProperty = null,
        public ?SourceRange $childRange = null,
    ) {}
}

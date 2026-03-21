<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLivewireNavigationReference
{
    /**
     * @param list<string> $navigateModifiers
     */
    public function __construct(
        public string $targetKind,
        public string $target,
        public SourceRange $targetRange,
        public array $navigateModifiers = [],
        public ?string $currentMode = null,
        public ?SourceRange $currentRange = null,
    ) {}
}

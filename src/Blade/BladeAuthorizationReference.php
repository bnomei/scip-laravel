<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeAuthorizationReference
{
    public function __construct(
        public string $directive,
        public string $ability,
        public SourceRange $abilityRange,
        public ?string $targetClassName = null,
        public ?SourceRange $targetClassRange = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLivewireSurfaceReference
{
    /**
     * @param list<string> $modifiers
     */
    public function __construct(
        public string $kind,
        public SourceRange $range,
        public ?string $name = null,
        public array $modifiers = [],
        public ?string $methodName = null,
        public ?SourceRange $methodRange = null,
        public ?string $targetName = null,
        public ?SourceRange $targetRange = null,
    ) {}
}

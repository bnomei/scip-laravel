<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class BladeLivewireEventReference
{
    public function __construct(
        public string $source,
        public string $kind,
        public string $eventName,
        public SourceRange $eventRange,
        public ?string $methodName = null,
        public ?SourceRange $methodRange = null,
    ) {}
}

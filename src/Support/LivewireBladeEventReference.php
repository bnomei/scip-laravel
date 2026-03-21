<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class LivewireBladeEventReference
{
    public function __construct(
        public string $eventName,
        public SourceRange $eventRange,
        public string $kind,
        public ?string $methodName = null,
        public ?SourceRange $methodRange = null,
    ) {}
}

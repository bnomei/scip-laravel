<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class LivewireEventReference
{
    public function __construct(
        public string $eventName,
        public SourceRange $range,
        public string $methodName,
        public int $methodLine,
        public string $kind,
    ) {}
}

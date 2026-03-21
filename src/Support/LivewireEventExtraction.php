<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class LivewireEventExtraction
{
    /**
     * @param list<LivewireEventReference> $references
     */
    public function __construct(
        public string $className,
        public array $references,
    ) {}
}

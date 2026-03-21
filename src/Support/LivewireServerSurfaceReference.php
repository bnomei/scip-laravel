<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class LivewireServerSurfaceReference
{
    public function __construct(
        public string $filePath,
        public string $kind,
        public string $name,
        public SourceRange $range,
    ) {}
}

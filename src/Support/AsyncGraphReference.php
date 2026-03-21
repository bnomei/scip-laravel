<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class AsyncGraphReference
{
    public function __construct(
        public string $filePath,
        public string $kind,
        public string $targetClass,
        public SourceRange $range,
    ) {}
}

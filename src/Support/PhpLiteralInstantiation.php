<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpLiteralInstantiation
{
    public function __construct(
        public string $filePath,
        public string $className,
        public string $literal,
        public SourceRange $range,
    ) {}
}

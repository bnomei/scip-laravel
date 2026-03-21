<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpClassConstantFetch
{
    public function __construct(
        public string $filePath,
        public string $className,
        public string $constantName,
        public SourceRange $range,
    ) {}
}

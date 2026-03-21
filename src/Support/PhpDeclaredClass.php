<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpDeclaredClass
{
    public function __construct(
        public string $className,
        public string $filePath,
        public int $lineNumber,
    ) {}
}

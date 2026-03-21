<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpAuthorizationReference
{
    public function __construct(
        public string $filePath,
        public string $ability,
        public SourceRange $range,
        public string $methodName,
        public int $methodLine,
    ) {}
}

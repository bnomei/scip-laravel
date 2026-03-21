<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpLiteralCall
{
    public function __construct(
        public string $filePath,
        public string $callee,
        public string $literal,
        public SourceRange $range,
    ) {}
}

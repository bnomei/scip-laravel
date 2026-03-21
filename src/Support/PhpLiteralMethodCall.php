<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpLiteralMethodCall
{
    public function __construct(
        public string $filePath,
        public string $helper,
        public ?string $helperLiteral,
        public string $method,
        public string $literal,
        public SourceRange $range,
    ) {}
}

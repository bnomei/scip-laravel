<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpReturnedArrayKey
{
    public function __construct(
        public string $filePath,
        public string $key,
        public SourceRange $range,
    ) {}
}

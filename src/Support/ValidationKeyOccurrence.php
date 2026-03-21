<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ValidationKeyOccurrence
{
    public function __construct(
        public string $key,
        public SourceRange $range,
        public int $syntaxKind,
    ) {}
}

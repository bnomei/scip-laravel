<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ValidationKeyMetadata
{
    /**
     * @param list<string> $documentation
     */
    public function __construct(
        public string $key,
        public array $documentation,
        public ?SourceRange $range = null,
        public int $syntaxKind = 0,
    ) {}
}

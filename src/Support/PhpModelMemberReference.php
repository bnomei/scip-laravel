<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpModelMemberReference
{
    public function __construct(
        public string $filePath,
        public string $modelClass,
        public string $memberName,
        public SourceRange $range,
        public bool $write,
        public bool $methodCall = false,
        public bool $constantFetch = false,
    ) {}
}

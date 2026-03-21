<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ConsoleCommandDefinition
{
    public function __construct(
        public string $filePath,
        public string $signature,
        public SourceRange $range,
        public ?string $className = null,
    ) {}
}

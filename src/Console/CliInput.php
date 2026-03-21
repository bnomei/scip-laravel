<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Console;

final readonly class CliInput
{
    /**
     * @param list<non-empty-string> $features
     */
    public function __construct(
        public bool $help,
        public ?string $targetRoot,
        public ?string $outputPath,
        public ?string $configPath,
        public ?string $mode,
        public bool $strict,
        public array $features,
    ) {}
}

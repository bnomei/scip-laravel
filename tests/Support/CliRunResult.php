<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Support;

final readonly class CliRunResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}

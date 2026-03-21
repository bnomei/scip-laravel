<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ConsoleScheduleReference
{
    /**
     * @param list<string> $documentation
     */
    public function __construct(
        public string $filePath,
        public string $kind,
        public SourceRange $range,
        public array $documentation,
        public ?string $signature = null,
        public ?string $className = null,
        public ?string $methodName = null,
    ) {}
}

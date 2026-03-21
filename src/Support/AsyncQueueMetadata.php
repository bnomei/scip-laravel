<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class AsyncQueueMetadata
{
    /**
     * @param list<array{class: string, range: SourceRange}> $middleware
     * @param list<string> $documentation
     */
    public function __construct(
        public string $className,
        public string $filePath,
        public array $middleware,
        public array $documentation,
    ) {}
}

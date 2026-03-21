<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Support\PipelineWarning;

final readonly class ExecutionResult
{
    /**
     * @param list<PipelineWarning> $warnings
     */
    public function __construct(
        public string $outputPath,
        public int $documentCount,
        public array $warnings,
    ) {}
}

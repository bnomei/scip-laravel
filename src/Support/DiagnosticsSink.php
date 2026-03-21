<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use function array_values;
use function ksort;

final class DiagnosticsSink
{
    /**
     * @var array<string, PipelineWarning>
     */
    private array $warnings = [];

    public function warning(string $source, string $message, ?string $code = null): void
    {
        $warning = new PipelineWarning($source, $message, $code);
        $this->warnings[$warning->key()] = $warning;
    }

    /**
     * @return list<PipelineWarning>
     */
    public function warnings(): array
    {
        ksort($this->warnings);

        return array_values($this->warnings);
    }
}

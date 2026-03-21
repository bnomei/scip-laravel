<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PipelineWarning
{
    public function __construct(
        public string $source,
        public string $message,
        public ?string $code = null,
    ) {}

    public function key(): string
    {
        return $this->source . '|' . ($this->code ?? '') . '|' . $this->message;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class LivewireValidationExtraction
{
    /**
     * @param list<ValidationKeyOccurrence> $occurrences
     * @param list<ValidationKeyMetadata> $metadata
     */
    public function __construct(
        public string $className,
        public array $occurrences,
        public array $metadata,
    ) {}
}

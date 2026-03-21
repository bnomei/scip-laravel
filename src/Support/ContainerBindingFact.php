<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ContainerBindingFact
{
    public function __construct(
        public string $filePath,
        public string $kind,
        public string $bindingType,
        public ?string $contractClass,
        public ?SourceRange $contractRange,
        public ?string $implementationClass,
        public ?SourceRange $implementationRange,
        public ?string $consumerClass = null,
        public ?SourceRange $consumerRange = null,
        public ?string $sourceClassName = null,
        public ?int $sourceClassLine = null,
        public ?string $contextDomain = null,
        public ?string $contextValue = null,
        public ?SourceRange $contextRange = null,
        public array $environments = [],
    ) {}
}

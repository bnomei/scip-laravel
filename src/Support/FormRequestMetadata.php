<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class FormRequestMetadata
{
    /**
     * @param list<string> $classDocumentation
     * @param list<string> $rulesMethodDocumentation
     */
    public function __construct(
        public string $className,
        public int $classLine,
        public array $classDocumentation,
        public ?int $rulesMethodLine = null,
        public array $rulesMethodDocumentation = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class BladeClassComponentContext
{
    /**
     * @param array<string, string> $propertySymbols
     * @param list<string> $aliases
     */
    public function __construct(
        public string $className,
        public string $classSymbol,
        public array $propertySymbols,
        public ?string $documentPath = null,
        public array $aliases = [],
    ) {}
}

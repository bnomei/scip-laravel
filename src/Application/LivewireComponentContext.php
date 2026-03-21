<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;

final readonly class LivewireComponentContext
{
    /**
     * @param array<string, string> $propertySymbols
     * @param array<string, string> $methodSymbols
     * @param array<string, string> $propertyTypes
     * @param array<string, string> $mountParameterTypes
     * @param list<string> $modelableProperties
     * @param list<string> $reactiveProperties
     * @param list<string> $componentAliases
     * @param list<DocumentSymbolPatch> $symbolPatches
     * @param list<DocumentOccurrencePatch> $definitionPatches
     */
    public function __construct(
        public string $documentPath,
        public array $propertySymbols,
        public array $methodSymbols,
        public array $propertyTypes = [],
        public array $mountParameterTypes = [],
        public array $modelableProperties = [],
        public array $reactiveProperties = [],
        public ?string $componentClassName = null,
        public array $componentAliases = [],
        public array $symbolPatches = [],
        public array $definitionPatches = [],
    ) {}
}

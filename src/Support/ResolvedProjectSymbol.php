<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;

final readonly class ResolvedProjectSymbol
{
    public function __construct(
        public string $symbol,
        public string $documentPath,
        public ?DocumentSymbolPatch $symbolPatch = null,
        public ?DocumentOccurrencePatch $definitionPatch = null,
    ) {}
}

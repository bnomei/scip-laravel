<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline;

use Scip\SymbolInformation;

final readonly class DocumentSymbolPatch
{
    public function __construct(
        public string $documentPath,
        public SymbolInformation $symbol,
    ) {}
}

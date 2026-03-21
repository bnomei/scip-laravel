<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline;

use Bnomei\ScipLaravel\Support\PipelineWarning;
use Scip\Document;
use Scip\SymbolInformation;

final readonly class IndexPatch
{
    /**
     * @param list<Document> $documents
     * @param list<DocumentSymbolPatch> $symbols
     * @param list<SymbolInformation> $externalSymbols
     * @param list<DocumentOccurrencePatch> $occurrences
     * @param list<PipelineWarning> $warnings
     */
    public function __construct(
        public array $documents = [],
        public array $symbols = [],
        public array $externalSymbols = [],
        public array $occurrences = [],
        public array $warnings = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline;

use Scip\Occurrence;

final readonly class DocumentOccurrencePatch
{
    public function __construct(
        public string $documentPath,
        public Occurrence $occurrence,
    ) {}
}

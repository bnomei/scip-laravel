<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class FluxComponentContractInventory
{
    /**
     * @param array<string, list<string>> $localDocumentationByViewName
     * @param array<string, list<string>> $externalDocumentationByTag
     */
    public function __construct(
        public array $localDocumentationByViewName = [],
        public array $externalDocumentationByTag = [],
    ) {}
}

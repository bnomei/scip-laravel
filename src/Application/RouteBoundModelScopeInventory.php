<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class RouteBoundModelScopeInventory
{
    /**
     * @param array<string, array<string, string>> $scopesByDocumentPath
     */
    public function __construct(
        public array $scopesByDocumentPath = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function forDocument(string $documentPath): array
    {
        return $this->scopesByDocumentPath[$documentPath] ?? [];
    }
}

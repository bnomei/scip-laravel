<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class BladeClassComponentInventory
{
    /**
     * @param array<string, BladeClassComponentContext> $contextsByAlias
     * @param array<string, BladeClassComponentContext> $contextsByDocumentPath
     */
    public function __construct(
        public array $contextsByAlias,
        public array $contextsByDocumentPath,
    ) {}

    public function forAlias(string $alias): ?BladeClassComponentContext
    {
        return $this->contextsByAlias[$alias] ?? null;
    }

    public function forDocument(string $documentPath): ?BladeClassComponentContext
    {
        return $this->contextsByDocumentPath[$documentPath] ?? null;
    }
}

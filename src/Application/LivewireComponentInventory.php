<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;

use function array_values;

final readonly class LivewireComponentInventory
{
    /**
     * @param array<string, LivewireComponentContext> $contextsByDocumentPath
     */
    public array $contextsByDocumentPath;

    /**
     * @var array<string, LivewireComponentContext>
     */
    public array $contextsByClassName;

    /**
     * @var array<string, LivewireComponentContext>
     */
    public array $contextsByAlias;

    public function __construct(array $contextsByDocumentPath = [])
    {
        $this->contextsByDocumentPath = $contextsByDocumentPath;
        $this->contextsByClassName = $this->uniqueClassContexts($contextsByDocumentPath);
        $this->contextsByAlias = $this->uniqueAliasContexts($contextsByDocumentPath);
    }

    public function forDocument(string $documentPath): ?LivewireComponentContext
    {
        return $this->contextsByDocumentPath[$documentPath] ?? null;
    }

    public function forClassName(string $className): ?LivewireComponentContext
    {
        return $this->contextsByClassName[$className] ?? null;
    }

    public function forAlias(string $alias): ?LivewireComponentContext
    {
        return $this->contextsByAlias[$alias] ?? null;
    }

    /**
     * @return list<DocumentSymbolPatch>
     */
    public function symbolPatches(): array
    {
        $patches = [];

        foreach ($this->contextsByDocumentPath as $context) {
            foreach ($context->symbolPatches as $patch) {
                $patches[$patch->documentPath . "\x1F" . $patch->symbol->getSymbol()] = $patch;
            }
        }

        return array_values($patches);
    }

    /**
     * @return list<DocumentOccurrencePatch>
     */
    public function definitionPatches(): array
    {
        $patches = [];

        foreach ($this->contextsByDocumentPath as $context) {
            foreach ($context->definitionPatches as $patch) {
                $occurrence = $patch->occurrence;
                $patches[$patch->documentPath
                    . "\x1F"
                    . $occurrence->getSymbol()
                    . "\x1F"
                    . implode(':', iterator_to_array($occurrence->getRange(), false))] = $patch;
            }
        }

        return array_values($patches);
    }

    /**
     * @param array<string, LivewireComponentContext> $contextsByDocumentPath
     * @return array<string, LivewireComponentContext>
     */
    private function uniqueClassContexts(array $contextsByDocumentPath): array
    {
        $unique = [];
        $duplicates = [];

        foreach ($contextsByDocumentPath as $context) {
            if ($context->componentClassName === null || $context->componentClassName === '') {
                continue;
            }

            if (isset($duplicates[$context->componentClassName])) {
                continue;
            }

            if (isset($unique[$context->componentClassName])) {
                unset($unique[$context->componentClassName]);
                $duplicates[$context->componentClassName] = true;

                continue;
            }

            $unique[$context->componentClassName] = $context;
        }

        return $unique;
    }

    /**
     * @param array<string, LivewireComponentContext> $contextsByDocumentPath
     * @return array<string, LivewireComponentContext>
     */
    private function uniqueAliasContexts(array $contextsByDocumentPath): array
    {
        $unique = [];
        $duplicates = [];

        foreach ($contextsByDocumentPath as $context) {
            foreach ($context->componentAliases as $alias) {
                if ($alias === '' || isset($duplicates[$alias])) {
                    continue;
                }

                if (isset($unique[$alias])) {
                    unset($unique[$alias]);
                    $duplicates[$alias] = true;

                    continue;
                }

                $unique[$alias] = $context;
            }
        }

        return $unique;
    }
}

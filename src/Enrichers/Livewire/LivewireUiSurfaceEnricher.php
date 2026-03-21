<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Livewire;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\LivewireComponentContext;
use Bnomei\ScipLaravel\Application\LivewireComponentInventoryBuilder;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeLivewireSurfaceReference;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\LivewireServerSurfaceExtractor;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use ReflectionClass;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_unique;
use function array_values;
use function in_array;
use function is_string;
use function ksort;
use function realpath;
use function sort;

final class LivewireUiSurfaceEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly BladeDirectiveScanner $scanner = new BladeDirectiveScanner(),
        private readonly LivewireComponentInventoryBuilder $inventoryBuilder = new LivewireComponentInventoryBuilder(),
        private readonly LivewireServerSurfaceExtractor $serverExtractor = new LivewireServerSurfaceExtractor(),
        private readonly FrameworkExternalSymbolFactory $externalSymbolFactory = new FrameworkExternalSymbolFactory(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        ?BladeRuntimeCache $bladeCache = null,
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    public function feature(): string
    {
        return 'views';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $inventory = $this->inventoryBuilder->collect($context);
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbols = [];
        $occurrences = [];
        $externalSymbols = [];
        $documentationBySymbol = [];
        $definedSyntheticSymbols = [];

        foreach ($inventory->contextsByDocumentPath as $documentPath => $componentContext) {
            $filePath = realpath($context->projectRoot . DIRECTORY_SEPARATOR . $documentPath)
            ?: $context->projectRoot . DIRECTORY_SEPARATOR . $documentPath;
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            foreach ($this->scanner->scanLivewireSurfaceReferences($contents) as $reference) {
                $this->collectBladeReference(
                    context: $context,
                    componentContext: $componentContext,
                    documentPath: $documentPath,
                    reference: $reference,
                    normalizer: $normalizer,
                    symbols: $symbols,
                    occurrences: $occurrences,
                    externalSymbols: $externalSymbols,
                    documentationBySymbol: $documentationBySymbol,
                    definedSyntheticSymbols: $definedSyntheticSymbols,
                );
            }
        }

        foreach ($this->serverExtractor->references($context->projectRoot) as $reference) {
            $symbol = $normalizer->domainSymbol('livewire-' . $reference->kind, $reference->name);
            $documentPath = $context->relativeProjectPath($reference->filePath);

            $this->defineSyntheticSymbol(
                documentPath: $documentPath,
                symbol: $symbol,
                displayName: $reference->name,
                documentation: ['Livewire ' . $reference->kind . ': ' . $reference->name],
                range: $reference->range->toScipRange(),
                symbols: $symbols,
                occurrences: $occurrences,
                definedSyntheticSymbols: $definedSyntheticSymbols,
            );

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));
        }

        foreach ($documentationBySymbol as $documentPath => $bySymbol) {
            ksort($bySymbol);

            foreach ($bySymbol as $symbol => $documentation) {
                sort($documentation);
                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'documentation' => array_values(array_unique($documentation)),
                ]));
            }
        }

        return $symbols === [] && $occurrences === [] && $externalSymbols === []
            ? IndexPatch::empty()
            : new IndexPatch(
                symbols: $symbols,
                externalSymbols: array_values($externalSymbols),
                occurrences: $occurrences,
            );
    }

    /**
     * @param array<string, array<string, list<string>>> $documentationBySymbol
     * @param array<string, true> $definedSyntheticSymbols
     * @param array<string, SymbolInformation> $externalSymbols
     * @param list<DocumentSymbolPatch> $symbols
     * @param list<DocumentOccurrencePatch> $occurrences
     */
    private function collectBladeReference(
        LaravelContext $context,
        LivewireComponentContext $componentContext,
        string $documentPath,
        BladeLivewireSurfaceReference $reference,
        SyntheticSymbolNormalizer $normalizer,
        array &$symbols,
        array &$occurrences,
        array &$externalSymbols,
        array &$documentationBySymbol,
        array &$definedSyntheticSymbols,
    ): void {
        if ($reference->kind === 'poll' && $reference->methodName !== null && $reference->methodRange !== null) {
            $symbol = $componentContext->methodSymbols[$reference->methodName] ?? null;

            if ($symbol !== null) {
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->methodRange->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));

                $ownerPath = $this->componentOwnerDocumentPath($context, $componentContext) ?? $documentPath;
                $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire poll';

                if ($reference->modifiers !== []) {
                    $documentationBySymbol[$ownerPath][$symbol][] =
                        'Livewire poll modifiers: ' . implode(', ', $reference->modifiers);
                }
            }

            return;
        }

        if ($reference->kind === 'upload' && $reference->name !== null) {
            $symbol = $componentContext->propertySymbols[$reference->name] ?? null;

            if ($symbol === null) {
                return;
            }

            $ownerPath = $this->componentOwnerDocumentPath($context, $componentContext) ?? $documentPath;
            $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire file upload property';

            if ($this->usesWithFileUploads($componentContext->componentClassName)) {
                $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire file uploads enabled via WithFileUploads';
            }

            return;
        }

        if (in_array($reference->kind, ['text', 'show', 'bind'], true) && $reference->name !== null) {
            $symbol = $componentContext->propertySymbols[$reference->name] ?? null;

            if ($symbol === null) {
                return;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => $reference->kind === 'bind'
                    ? SymbolRole::ReadAccess | SymbolRole::WriteAccess
                    : SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::Identifier,
            ]));

            $ownerPath = $this->componentOwnerDocumentPath($context, $componentContext) ?? $documentPath;
            $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire ' . $reference->kind;

            return;
        }

        if (
            in_array($reference->kind, ['init', 'sort'], true)
            && $reference->methodName !== null
            && $reference->methodRange !== null
        ) {
            $symbol = $componentContext->methodSymbols[$reference->methodName] ?? null;

            if ($symbol === null) {
                return;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->methodRange->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::Identifier,
            ]));

            $ownerPath = $this->componentOwnerDocumentPath($context, $componentContext) ?? $documentPath;
            $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire ' . $reference->kind;

            return;
        }

        if (
            $reference->kind === 'loading-target'
            && $reference->targetName !== null
            && $reference->targetRange !== null
        ) {
            $symbol =
                $componentContext->methodSymbols[$reference->targetName] ?? $componentContext->propertySymbols[$reference->targetName]
                    ?? null;

            if ($symbol === null) {
                return;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->targetRange->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::Identifier,
            ]));

            $ownerPath = $this->componentOwnerDocumentPath($context, $componentContext) ?? $documentPath;
            $documentationBySymbol[$ownerPath][$symbol][] = 'Livewire loading target';

            if ($reference->modifiers !== []) {
                $documentationBySymbol[$ownerPath][$symbol][] =
                    'Livewire loading modifiers: ' . implode(', ', $reference->modifiers);
            }

            return;
        }

        if ($reference->kind === 'ui-directive' && $reference->name !== null) {
            $symbol = $this->externalSymbolFactory->livewireUiDirective($reference->name);
            $externalSymbols[$symbol->getSymbol()] = $symbol;
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $symbol->getSymbol(),
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::TagAttribute,
            ]));

            return;
        }

        if (($reference->kind === 'stream' || $reference->kind === 'ref') && $reference->name !== null) {
            $symbol = $normalizer->domainSymbol('livewire-' . $reference->kind, $reference->name);

            $this->defineSyntheticSymbol(
                documentPath: $documentPath,
                symbol: $symbol,
                displayName: $reference->name,
                documentation: ['Livewire ' . $reference->kind . ': ' . $reference->name],
                range: $reference->range->toScipRange(),
                symbols: $symbols,
                occurrences: $occurrences,
                definedSyntheticSymbols: $definedSyntheticSymbols,
            );

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));
        }
    }

    private function componentOwnerDocumentPath(
        LaravelContext $context,
        LivewireComponentContext $componentContext,
    ): ?string {
        if ($componentContext->componentClassName === null || $componentContext->componentClassName === '') {
            return $componentContext->documentPath;
        }

        try {
            $reflection = new ReflectionClass($componentContext->componentClassName);
        } catch (Throwable) {
            return $componentContext->documentPath;
        }

        $filePath = $reflection->getFileName();

        return is_string($filePath) && $filePath !== ''
            ? $context->relativeProjectPath($filePath)
            : $componentContext->documentPath;
    }

    private function usesWithFileUploads(?string $className): bool
    {
        if ($className === null || $className === '') {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable) {
            return false;
        }

        foreach ($reflection->getTraitNames() as $traitName) {
            if ($traitName === 'Livewire\\WithFileUploads') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $documentation
     * @param array<string, true> $definedSyntheticSymbols
     * @param list<DocumentSymbolPatch> $symbols
     * @param list<DocumentOccurrencePatch> $occurrences
     * @param list<int> $range
     */
    private function defineSyntheticSymbol(
        string $documentPath,
        string $symbol,
        string $displayName,
        array $documentation,
        array $range,
        array &$symbols,
        array &$occurrences,
        array &$definedSyntheticSymbols,
    ): void {
        if (isset($definedSyntheticSymbols[$symbol])) {
            return;
        }

        $definedSyntheticSymbols[$symbol] = true;
        $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
            'symbol' => $symbol,
            'display_name' => $displayName,
            'kind' => Kind::Key,
            'documentation' => $documentation,
        ]));
        $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
            'range' => $range,
            'symbol' => $symbol,
            'symbol_roles' => SymbolRole::Definition,
            'syntax_kind' => SyntaxKind::StringLiteralKey,
        ]));
    }
}

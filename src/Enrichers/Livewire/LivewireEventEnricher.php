<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Livewire;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\LivewireEventExtractor;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_values;
use function ksort;
use function sort;

final class LivewireEventEnricher implements Enricher
{
    public function __construct(
        private readonly LivewireEventExtractor $extractor = new LivewireEventExtractor(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly FrameworkExternalSymbolFactory $externalSymbols = new FrameworkExternalSymbolFactory(),
    ) {}

    public function feature(): string
    {
        return 'views';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $symbols = [];
        $occurrences = [];
        $externalSymbols = [];
        $documentationByMethod = [];

        foreach ($this->livewirePhpFiles($context->projectRoot) as $filePath) {
            $extraction = $this->extractor->extract($filePath);

            if ($extraction === null) {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);

            foreach ($extraction->references as $reference) {
                $eventSymbol = $this->externalSymbols->livewireEvent($reference->eventName);
                $externalSymbols[$eventSymbol->getSymbol()] = $eventSymbol;
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $eventSymbol->getSymbol(),
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));

                $methodSymbol = $this->methodSymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    $reference->methodName,
                    $reference->methodLine,
                );

                if (!is_string($methodSymbol) || $methodSymbol === '') {
                    continue;
                }

                $documentationByMethod[$documentPath][$methodSymbol][] = match ($reference->kind) {
                    'listener' => 'Livewire event listener: ' . $reference->eventName,
                    default => 'Livewire event dispatch: ' . $reference->eventName,
                };
            }
        }

        ksort($documentationByMethod);

        foreach ($documentationByMethod as $documentPath => $bySymbol) {
            ksort($bySymbol);

            foreach ($bySymbol as $symbol => $documentation) {
                sort($documentation);
                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'documentation' => array_values(array_unique($documentation)),
                ]));
            }
        }

        if ($symbols === [] && $occurrences === [] && $externalSymbols === []) {
            return IndexPatch::empty();
        }

        ksort($externalSymbols);

        return new IndexPatch(
            symbols: $symbols,
            externalSymbols: array_values($externalSymbols),
            occurrences: $occurrences,
        );
    }

    /**
     * @return list<string>
     */
    private function livewirePhpFiles(string $projectRoot): array
    {
        $root = $projectRoot . '/app/Livewire';

        return is_dir($root) ? ProjectPhpAnalysisCache::shared()->phpFilesInRoots([$root]) : [];
    }
}

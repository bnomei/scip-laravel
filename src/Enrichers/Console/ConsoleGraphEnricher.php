<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Console;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\ConsoleGraphExtractor;
use Bnomei\ScipLaravel\Support\ProjectFallbackSymbolResolver;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_unique;
use function array_values;
use function ksort;
use function sort;

final class ConsoleGraphEnricher implements Enricher
{
    public function __construct(
        private readonly ConsoleGraphExtractor $extractor = new ConsoleGraphExtractor(),
        private readonly ProjectFallbackSymbolResolver $fallbackResolver = new ProjectFallbackSymbolResolver(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
    ) {}

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $symbols = [];
        $occurrences = [];
        $documentationBySymbol = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $commandSymbolsBySignature = [];
        $commandTargetsBySignature = [];

        foreach ($this->extractor->commandDefinitions($context->projectRoot) as $definition) {
            $documentPath = $context->relativeProjectPath($definition->filePath);
            $symbol = $normalizer->domainSymbol('artisan-command', $definition->signature);
            $commandSymbolsBySignature[$definition->signature] = $symbol;
            $commandTargetsBySignature[$definition->signature] = [
                'documentPath' => $documentPath,
                'symbol' => $symbol,
                'classDocumentPath' => null,
                'classSymbol' => null,
            ];
            $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $definition->signature,
                'kind' => Kind::Key,
                'documentation' => ['Laravel Artisan command: ' . $definition->signature],
            ]));
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $definition->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));

            if ($definition->className === null) {
                continue;
            }

            $classPayload = $this->fallbackResolver->resolveClass($context, $normalizer, $definition->className);

            if ($classPayload === null) {
                continue;
            }

            if ($classPayload->symbolPatch !== null) {
                $symbols[] = $classPayload->symbolPatch;
            }

            if ($classPayload->definitionPatch !== null) {
                $occurrences[] = $classPayload->definitionPatch;
            }

            $commandTargetsBySignature[$definition->signature]['classDocumentPath'] = $classPayload->documentPath;
            $commandTargetsBySignature[$definition->signature]['classSymbol'] = $classPayload->symbol;

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $definition->range->toScipRange(),
                'symbol' => $classPayload->symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::Identifier,
            ]));
            $documentationBySymbol[$classPayload->documentPath][$classPayload->symbol][] =
                'Laravel Artisan command: ' . $definition->signature;
        }

        foreach ($this->extractor->scheduleReferences($context->projectRoot) as $reference) {
            $documentPath = $context->relativeProjectPath($reference->filePath);

            if ($reference->kind === 'command' && $reference->signature !== null) {
                $symbol = $commandSymbolsBySignature[$reference->signature] ?? $normalizer->domainSymbol(
                    'artisan-command',
                    $reference->signature,
                );
                $definitionDocumentPath =
                    $commandTargetsBySignature[$reference->signature]['documentPath'] ?? $documentPath;
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));

                foreach ($reference->documentation as $line) {
                    $documentationBySymbol[$definitionDocumentPath][$symbol][] = $line;
                }

                $classDocumentPath = $commandTargetsBySignature[$reference->signature]['classDocumentPath'] ?? null;
                $classSymbol = $commandTargetsBySignature[$reference->signature]['classSymbol'] ?? null;

                if ($classDocumentPath !== null && $classSymbol !== null) {
                    foreach ($reference->documentation as $line) {
                        $documentationBySymbol[$classDocumentPath][$classSymbol][] = $line;
                    }
                }

                continue;
            }

            if ($reference->kind === 'job' && $reference->className !== null) {
                $classPayload = $this->fallbackResolver->resolveClass($context, $normalizer, $reference->className);

                if ($classPayload === null) {
                    continue;
                }

                if ($classPayload->symbolPatch !== null) {
                    $symbols[] = $classPayload->symbolPatch;
                }

                if ($classPayload->definitionPatch !== null) {
                    $occurrences[] = $classPayload->definitionPatch;
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $classPayload->symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));

                foreach ($reference->documentation as $line) {
                    $documentationBySymbol[$classPayload->documentPath][$classPayload->symbol][] = $line;
                }

                continue;
            }

            if ($reference->kind === 'callable' && $reference->className !== null && $reference->methodName !== null) {
                $methodPayload = $this->fallbackResolver->resolveMethod(
                    $context,
                    $normalizer,
                    $reference->className,
                    $reference->methodName,
                );

                if ($methodPayload === null) {
                    continue;
                }

                if ($methodPayload->symbolPatch !== null) {
                    $symbols[] = $methodPayload->symbolPatch;
                }

                if ($methodPayload->definitionPatch !== null) {
                    $occurrences[] = $methodPayload->definitionPatch;
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $methodPayload->symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));

                foreach ($reference->documentation as $line) {
                    $documentationBySymbol[$methodPayload->documentPath][$methodPayload->symbol][] = $line;
                }
            }
        }

        ksort($documentationBySymbol);

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

        return $symbols === [] && $occurrences === []
            ? IndexPatch::empty()
            : new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }
}

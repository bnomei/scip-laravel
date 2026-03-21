<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Async;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\AsyncGraphExtractor;
use Bnomei\ScipLaravel\Support\ProjectFallbackSymbolResolver;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use ReflectionClass;
use ReflectionException;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_unique;
use function array_values;
use function is_string;
use function ksort;
use function sort;
use function str_starts_with;

final class AsyncGraphEnricher implements Enricher
{
    public function __construct(
        private readonly AsyncGraphExtractor $extractor = new AsyncGraphExtractor(),
        private readonly ProjectFallbackSymbolResolver $fallbackResolver = new ProjectFallbackSymbolResolver(),
        private readonly FrameworkExternalSymbolFactory $externalSymbols = new FrameworkExternalSymbolFactory(),
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
        $externalSymbols = [];
        $documentationBySymbol = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));

        foreach ($this->extractor->references($context->projectRoot) as $reference) {
            $payload = $this->classSymbolPayload($context, $normalizer, $reference->targetClass);

            if ($payload === null) {
                continue;
            }

            if ($payload['symbolPatch'] !== null) {
                $symbols[] = $payload['symbolPatch'];
            }

            if ($payload['definitionPatch'] !== null) {
                $occurrences[] = $payload['definitionPatch'];
            }

            if ($payload['external'] !== null) {
                $externalSymbols[$payload['external']->getSymbol()] = $payload['external'];
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($reference->filePath),
                occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $payload['symbol'],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]),
            );
        }

        foreach ($this->extractor->queueMetadata($context->projectRoot) as $metadata) {
            $classPayload = $this->classSymbolPayload($context, $normalizer, $metadata->className);

            if ($classPayload === null || $classPayload['documentPath'] === null) {
                continue;
            }

            if ($classPayload['symbolPatch'] !== null) {
                $symbols[] = $classPayload['symbolPatch'];
            }

            if ($classPayload['definitionPatch'] !== null) {
                $occurrences[] = $classPayload['definitionPatch'];
            }

            foreach ($metadata->documentation as $line) {
                $documentationBySymbol[$classPayload['documentPath']][$classPayload['symbol']][] = $line;
            }

            foreach ($metadata->middleware as $middleware) {
                $middlewarePayload = $this->classSymbolPayload($context, $normalizer, $middleware['class']);

                if ($middlewarePayload === null) {
                    continue;
                }

                if ($middlewarePayload['symbolPatch'] !== null) {
                    $symbols[] = $middlewarePayload['symbolPatch'];
                }

                if ($middlewarePayload['definitionPatch'] !== null) {
                    $occurrences[] = $middlewarePayload['definitionPatch'];
                }

                if ($middlewarePayload['external'] !== null) {
                    $externalSymbols[$middlewarePayload['external']->getSymbol()] = $middlewarePayload['external'];
                }

                $occurrences[] = new DocumentOccurrencePatch(
                    documentPath: $context->relativeProjectPath($metadata->filePath),
                    occurrence: new Occurrence([
                        'range' => $middleware['range']->toScipRange(),
                        'symbol' => $middlewarePayload['symbol'],
                        'symbol_roles' => SymbolRole::ReadAccess,
                        'syntax_kind' => SyntaxKind::Identifier,
                    ]),
                );
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
     * @return ?array{
     *   symbol: string,
     *   documentPath: ?string,
     *   external: ?SymbolInformation,
     *   symbolPatch: ?DocumentSymbolPatch,
     *   definitionPatch: ?DocumentOccurrencePatch
     * }
     */
    private function classSymbolPayload(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $className,
    ): ?array {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            $external = $this->externalSymbols->phpClass($className);

            return [
                'symbol' => $external->getSymbol(),
                'documentPath' => null,
                'external' => $external,
                'symbolPatch' => null,
                'definitionPatch' => null,
            ];
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);

        if (!str_starts_with($documentPath, 'app/')) {
            $external = $this->externalSymbols->phpClass($className);

            return [
                'symbol' => $external->getSymbol(),
                'documentPath' => null,
                'external' => $external,
                'symbolPatch' => null,
                'definitionPatch' => null,
            ];
        }

        $resolved = $this->fallbackResolver->resolveClass($context, $normalizer, $className);

        if ($resolved === null) {
            return null;
        }

        return [
            'symbol' => $resolved->symbol,
            'documentPath' => $resolved->documentPath,
            'external' => null,
            'symbolPatch' => $resolved->symbolPatch,
            'definitionPatch' => $resolved->definitionPatch,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Container;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\ContainerBindingExtractor;
use Bnomei\ScipLaravel\Support\ContainerBindingFact;
use Bnomei\ScipLaravel\Support\ProjectFallbackSymbolResolver;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use ReflectionClass;
use ReflectionException;
use Scip\Occurrence;
use Scip\Relationship;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_unique;
use function array_values;
use function is_string;
use function ksort;
use function ltrim;
use function sort;
use function str_starts_with;

final class ContainerEnricher implements Enricher
{
    public function __construct(
        private readonly ContainerBindingExtractor $extractor = new ContainerBindingExtractor(),
        private readonly FrameworkExternalSymbolFactory $externalSymbols = new FrameworkExternalSymbolFactory(),
        private readonly ProjectFallbackSymbolResolver $fallbackSymbolResolver = new ProjectFallbackSymbolResolver(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
    ) {}

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $occurrences = [];
        $symbols = [];
        $externalSymbols = [];
        $documentationBySymbol = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));

        foreach ($this->extractor->extract($context->projectRoot) as $fact) {
            $documentPath = $context->relativeProjectPath($fact->filePath);

            if (
                $fact->kind === 'contextual-attribute'
                && $fact->contextDomain !== null
                && $fact->contextValue !== null
                && $fact->contextRange !== null
            ) {
                $symbol = null;

                if ($fact->contextDomain === 'config') {
                    $symbol = $normalizer->config($fact->contextValue);
                } else {
                    $external = $this->externalSymbols->contextualAttribute($fact->contextDomain, $fact->contextValue);
                    $externalSymbols[$external->getSymbol()] = $external;
                    $symbol = $external->getSymbol();
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $fact->contextRange->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));

                if (is_string($fact->sourceClassName) && $fact->sourceClassName !== '') {
                    $ownerPayload = $this->classSymbolPayload($context, $normalizer, $fact->sourceClassName);

                    if ($ownerPayload !== null && $ownerPayload['documentPath'] !== null) {
                        $documentationBySymbol[$ownerPayload['documentPath']][$ownerPayload['symbol']][] =
                            'Laravel contextual attribute: ' . $fact->contextDomain . ' => ' . $fact->contextValue;
                    }
                }

                continue;
            }

            foreach ([
                ['class' => $fact->contractClass, 'range' => $fact->contractRange, 'syntax' => SyntaxKind::Identifier],
                [
                    'class' => $fact->implementationClass,
                    'range' => $fact->implementationRange,
                    'syntax' => SyntaxKind::Identifier,
                ],
                ['class' => $fact->consumerClass, 'range' => $fact->consumerRange, 'syntax' => SyntaxKind::Identifier],
            ] as $reference) {
                $className = $reference['class'];
                $range = $reference['range'];

                if (!is_string($className) || $className === '' || $range === null) {
                    continue;
                }

                $payload = $this->classSymbolPayload($context, $normalizer, $className);

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

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $range->toScipRange(),
                    'symbol' => $payload['symbol'],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $reference['syntax'],
                ]));
            }

            if (
                $fact->contractClass !== null
                && $fact->implementationClass !== null
                && ($fact->kind === 'binding' || $fact->kind === 'attribute' || $fact->kind === 'contextual')
            ) {
                $contractPayload = $this->classSymbolPayload($context, $normalizer, $fact->contractClass);
                $implementationPayload = $this->classSymbolPayload($context, $normalizer, $fact->implementationClass);

                if (
                    $contractPayload !== null
                    && $implementationPayload !== null
                    && $implementationPayload['documentPath'] !== null
                ) {
                    $symbols[] =
                        new DocumentSymbolPatch(documentPath: $implementationPayload['documentPath'], symbol: new SymbolInformation([
                            'symbol' => $implementationPayload['symbol'],
                            'relationships' => [
                                new Relationship([
                                    'symbol' => $contractPayload['symbol'],
                                    'is_reference' => true,
                                    'is_implementation' => true,
                                ]),
                            ],
                        ]));
                }
            }

            $this->appendDocumentation($documentationBySymbol, $context, $normalizer, $fact);
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
     * @param array<string, array<string, list<string>>> $documentationBySymbol
     */
    private function appendDocumentation(
        array &$documentationBySymbol,
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        ContainerBindingFact $fact,
    ): void {
        $bindingLine = $this->bindingDocumentation($fact);
        $lifetimeLine = $this->lifetimeDocumentation($fact);

        foreach ([$fact->contractClass, $fact->implementationClass] as $className) {
            if (!is_string($className) || $className === '') {
                continue;
            }

            $payload = $this->classSymbolPayload($context, $normalizer, $className);

            if ($payload === null || $payload['documentPath'] === null || $bindingLine === null) {
                continue;
            }

            $documentationBySymbol[$payload['documentPath']][$payload['symbol']][] = $bindingLine;
        }

        if ($lifetimeLine === null || !is_string($fact->sourceClassName) || $fact->sourceClassName === '') {
            return;
        }

        $payload = $this->classSymbolPayload($context, $normalizer, $fact->sourceClassName);

        if ($payload === null || $payload['documentPath'] === null) {
            return;
        }

        $documentationBySymbol[$payload['documentPath']][$payload['symbol']][] = $lifetimeLine;
    }

    private function bindingDocumentation(ContainerBindingFact $fact): ?string
    {
        if ($fact->kind === 'contextual') {
            if ($fact->consumerClass === null || $fact->contractClass === null || $fact->implementationClass === null) {
                return null;
            }

            return (
                'Laravel contextual binding: '
                . $fact->consumerClass
                . ' needs '
                . $fact->contractClass
                . ' -> '
                . $fact->implementationClass
            );
        }

        if (
            $fact->bindingType === 'singleton'
            || $fact->bindingType === 'scoped'
            || $fact->bindingType === 'bind'
            || $fact->bindingType === 'instance'
        ) {
            if ($fact->contractClass === null || $fact->implementationClass === null) {
                return null;
            }

            $line =
                'Laravel container binding ('
                . $fact->bindingType
                . '): '
                . $fact->contractClass
                . ' -> '
                . $fact->implementationClass;

            if ($fact->environments !== []) {
                $line .= ' [environments: ' . implode(', ', $fact->environments) . ']';
            }

            return $line;
        }

        return null;
    }

    private function lifetimeDocumentation(ContainerBindingFact $fact): ?string
    {
        return match ($fact->bindingType) {
            'singleton' => 'Laravel container lifetime: singleton',
            'scoped' => 'Laravel container lifetime: scoped',
            default => null,
        };
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
            $external = $this->externalSymbols->phpClass($className);

            return [
                'symbol' => $external->getSymbol(),
                'documentPath' => null,
                'external' => $external,
                'symbolPatch' => null,
                'definitionPatch' => null,
            ];
        }

        $relativePath = $context->relativeProjectPath($filePath);

        if (!str_starts_with($relativePath, 'app/')) {
            $external = $this->externalSymbols->phpClass($className);

            return [
                'symbol' => $external->getSymbol(),
                'documentPath' => null,
                'external' => $external,
                'symbolPatch' => null,
                'definitionPatch' => null,
            ];
        }

        $fallback = $this->fallbackSymbolResolver->resolveClass($context, $normalizer, ltrim($className, '\\'));

        if ($fallback !== null) {
            return [
                'symbol' => $fallback->symbol,
                'documentPath' => $fallback->documentPath,
                'external' => null,
                'symbolPatch' => $fallback->symbolPatch,
                'definitionPatch' => $fallback->definitionPatch,
            ];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Livewire;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\LivewireComponentContext;
use Bnomei\ScipLaravel\Application\LivewireComponentInventoryBuilder;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\LivewireValidationExtractor;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\ValidationKeyMetadata;
use Bnomei\ScipLaravel\Support\ValidationKeyOccurrence;
use Bnomei\ScipLaravel\Support\ValidationRuleFormatter;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use ReflectionClass;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Throwable;

use function array_key_exists;
use function array_unique;
use function array_values;
use function is_array;
use function is_dir;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function realpath;
use function sort;
use function str_contains;

final class ValidationEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly LivewireComponentInventoryBuilder $livewireInventoryBuilder = new LivewireComponentInventoryBuilder(),
        private readonly LivewireValidationExtractor $validationExtractor = new LivewireValidationExtractor(),
        private readonly BladeDirectiveScanner $bladeDirectiveScanner = new BladeDirectiveScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly ValidationRuleFormatter $validationRuleFormatter = new ValidationRuleFormatter(),
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    public function feature(): string
    {
        return 'views';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $inventory = $this->livewireInventoryBuilder->collect($context);
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbols = [];
        $occurrences = [];
        $syntheticDefinitions = [];
        $symbolDocs = [];
        $routeRuleDocumentationByKey = $this->routeRuleDocumentationByKey($context);

        foreach ($this->livewirePhpFiles($context->projectRoot) as $filePath) {
            $extraction = $this->validationExtractor->extract($filePath);

            if ($extraction === null) {
                continue;
            }

            $relativePath = $context->relativeProjectPath($filePath);
            $componentContext = $inventory->forClassName($extraction->className);

            foreach ($extraction->occurrences as $keyOccurrence) {
                $resolved = $this->resolvedValidationTarget(
                    context: $context,
                    componentContext: $componentContext,
                    className: $extraction->className,
                    key: $keyOccurrence->key,
                    fallbackDocumentPath: $relativePath,
                    normalizer: $normalizer,
                );

                if ($resolved === null) {
                    continue;
                }

                $this->defineSyntheticTarget(
                    symbols: $symbols,
                    occurrences: $occurrences,
                    syntheticDefinitions: $syntheticDefinitions,
                    resolved: $resolved,
                    range: $keyOccurrence->range->toScipRange(),
                    syntaxKind: $keyOccurrence->syntaxKind,
                );

                $this->appendSymbolDocumentation(
                    symbolDocs: $symbolDocs,
                    resolved: $resolved,
                    documentation: $routeRuleDocumentationByKey[$keyOccurrence->key] ?? [],
                );

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                    'range' => $keyOccurrence->range->toScipRange(),
                    'symbol' => $resolved['symbol'],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $keyOccurrence->syntaxKind,
                    'override_documentation' => ['Validation key: ' . $keyOccurrence->key],
                ]));
            }

            foreach ($extraction->metadata as $metadata) {
                $resolved = $this->resolvedValidationTarget(
                    context: $context,
                    componentContext: $componentContext,
                    className: $extraction->className,
                    key: $metadata->key,
                    fallbackDocumentPath: $relativePath,
                    normalizer: $normalizer,
                );

                if ($resolved === null) {
                    continue;
                }

                $this->defineSyntheticTarget(
                    symbols: $symbols,
                    occurrences: $occurrences,
                    syntheticDefinitions: $syntheticDefinitions,
                    resolved: $resolved,
                    range: $metadata->range?->toScipRange(),
                    syntaxKind: $metadata->syntaxKind,
                );

                $this->appendSymbolDocumentation(
                    symbolDocs: $symbolDocs,
                    resolved: $resolved,
                    documentation: array_values(array_unique([
                        ...$metadata->documentation,
                        ...($routeRuleDocumentationByKey[$metadata->key] ?? []),
                    ])),
                );
            }
        }

        foreach ($inventory->contextsByDocumentPath as $documentPath => $componentContext) {
            $filePath = realpath($context->projectRoot . DIRECTORY_SEPARATOR . $documentPath)
            ?: $context->projectRoot . DIRECTORY_SEPARATOR . $documentPath;
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            foreach ($this->bladeDirectiveScanner->scanValidationReferences($contents) as $reference) {
                $resolved = $this->resolvedValidationTarget(
                    context: $context,
                    componentContext: $componentContext,
                    className: $componentContext->componentClassName,
                    key: $reference->literal,
                    fallbackDocumentPath: $documentPath,
                    normalizer: $normalizer,
                );

                if ($resolved === null) {
                    continue;
                }

                $this->defineSyntheticTarget(
                    symbols: $symbols,
                    occurrences: $occurrences,
                    syntheticDefinitions: $syntheticDefinitions,
                    resolved: $resolved,
                    range: $reference->range->toScipRange(),
                    syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
                );

                $this->appendSymbolDocumentation(
                    symbolDocs: $symbolDocs,
                    resolved: $resolved,
                    documentation: $routeRuleDocumentationByKey[$reference->literal] ?? [],
                );

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $resolved['symbol'],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => \Scip\SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Validation key: ' . $reference->literal],
                ]));
            }
        }

        ksort($symbolDocs);

        foreach ($symbolDocs as $payload) {
            $symbols[] = new DocumentSymbolPatch(documentPath: $payload['documentPath'], symbol: new SymbolInformation([
                'symbol' => $payload['symbol'],
                'display_name' => $payload['displayName'],
                'kind' => $payload['kind'],
                'documentation' => $payload['documentation'],
            ]));
        }

        if ($symbols === [] && $occurrences === []) {
            return IndexPatch::empty();
        }

        return new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @return array<string, list<string>>
     */
    private function routeRuleDocumentationByKey(LaravelContext $context): array
    {
        $docs = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            $validator = method_exists($route, 'requestValidator') ? $route->requestValidator() : null;

            if (!$validator instanceof \Laravel\Ranger\Components\Validator || $validator->rules === []) {
                continue;
            }

            foreach ($validator->rules as $key => $rules) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $formatted = $this->validationRuleFormatter->formatRangerRules($rules);

                if ($formatted === '') {
                    continue;
                }

                $docs[$key][] = 'Route validator rules: ' . $formatted;
            }
        }

        foreach ($docs as $key => $lines) {
            $lines = array_values(array_unique($lines));
            sort($lines);
            $docs[$key] = $lines;
        }

        ksort($docs);

        return $docs;
    }

    /**
     * @param array<string, array{documentPath: string, symbol: string, displayName: string, kind: int, documentation: list<string>}> $symbolDocs
     * @param array{symbol: string, documentPath: string, displayName: string, kind: int, synthetic: bool} $resolved
     * @param list<string> $documentation
     */
    private function appendSymbolDocumentation(array &$symbolDocs, array $resolved, array $documentation): void
    {
        if ($documentation === []) {
            return;
        }

        $key = $resolved['documentPath'] . "\n" . $resolved['symbol'];

        if (!isset($symbolDocs[$key])) {
            $symbolDocs[$key] = [
                'documentPath' => $resolved['documentPath'],
                'symbol' => $resolved['symbol'],
                'displayName' => $resolved['displayName'],
                'kind' => $resolved['kind'],
                'documentation' => [],
            ];
        }

        $symbolDocs[$key]['documentation'] = array_values(array_unique([
            ...$symbolDocs[$key]['documentation'],
            ...$documentation,
        ]));
        sort($symbolDocs[$key]['documentation']);
    }

    /**
     * @return ?array{symbol: string, documentPath: string, displayName: string, kind: int, synthetic: bool}
     */
    private function resolvedValidationTarget(
        LaravelContext $context,
        ?LivewireComponentContext $componentContext,
        ?string $className,
        string $key,
        string $fallbackDocumentPath,
        SyntheticSymbolNormalizer $normalizer,
    ): ?array {
        if ($key === '' || str_contains($key, '*')) {
            return null;
        }

        if (!str_contains($key, '.')) {
            $direct = $className === null
                ? null
                : $this->canonicalPropertyTarget($context, $className, $key, $componentContext);

            if ($direct !== null) {
                return $direct;
            }

            return [
                'symbol' => $normalizer->validationKey($key),
                'documentPath' => $fallbackDocumentPath,
                'displayName' => $key,
                'kind' => Kind::Key,
                'synthetic' => true,
            ];
        }

        if ($componentContext !== null) {
            [$head, $tail] = explode('.', $key, 2);

            if ($head !== '' && $tail !== '' && !str_contains($tail, '.')) {
                $formClass = $componentContext->propertyTypes[$head] ?? null;

                if (is_string($formClass) && $formClass !== '') {
                    $formTarget = $this->canonicalPropertyTarget($context, $formClass, $tail);

                    if ($formTarget !== null) {
                        return $formTarget;
                    }
                }
            }
        }

        return [
            'symbol' => $normalizer->validationKey($key),
            'documentPath' => $fallbackDocumentPath,
            'displayName' => $key,
            'kind' => Kind::Key,
            'synthetic' => true,
        ];
    }

    /**
     * @return ?array{symbol: string, documentPath: string, displayName: string, kind: int, synthetic: bool}
     */
    private function canonicalPropertyTarget(
        LaravelContext $context,
        string $className,
        string $propertyName,
        ?LivewireComponentContext $componentContext = null,
    ): ?array {
        if ($componentContext !== null && $componentContext->componentClassName === $className) {
            $symbol = $componentContext->propertySymbols[$propertyName] ?? null;

            if (is_string($symbol) && $symbol !== '') {
                $documentPath = $this->classDocumentPath($context, $className);

                if ($documentPath !== null) {
                    return [
                        'symbol' => $symbol,
                        'documentPath' => $documentPath,
                        'displayName' => $propertyName,
                        'kind' => Kind::Property,
                        'synthetic' => false,
                    ];
                }
            }
        }

        $documentPath = $this->classDocumentPath($context, $className);

        if ($documentPath === null) {
            return null;
        }

        $symbol = $this->propertySymbolResolver->resolve(
            $context->baselineIndex,
            $documentPath,
            ltrim($className, '\\'),
            $propertyName,
        );

        if (!is_string($symbol) || $symbol === '') {
            return null;
        }

        return [
            'symbol' => $symbol,
            'documentPath' => $documentPath,
            'displayName' => $propertyName,
            'kind' => Kind::Property,
            'synthetic' => false,
        ];
    }

    /**
     * @param list<DocumentSymbolPatch> $symbols
     * @param list<DocumentOccurrencePatch> $occurrences
     * @param array<string, true> $syntheticDefinitions
     * @param array{symbol: string, documentPath: string, displayName: string, kind: int, synthetic: bool} $resolved
     * @param ?array{int, int, int, int} $range
     */
    private function defineSyntheticTarget(
        array &$symbols,
        array &$occurrences,
        array &$syntheticDefinitions,
        array $resolved,
        ?array $range,
        int $syntaxKind,
    ): void {
        if (!$resolved['synthetic'] || isset($syntheticDefinitions[$resolved['symbol']])) {
            return;
        }

        $symbols[] = new DocumentSymbolPatch(documentPath: $resolved['documentPath'], symbol: new SymbolInformation([
            'symbol' => $resolved['symbol'],
            'display_name' => $resolved['displayName'],
            'kind' => $resolved['kind'],
        ]));

        if ($range !== null) {
            $occurrences[] =
                new DocumentOccurrencePatch(documentPath: $resolved['documentPath'], occurrence: new Occurrence([
                    'range' => $range,
                    'symbol' => $resolved['symbol'],
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => $syntaxKind,
                ]));
        }

        $syntheticDefinitions[$resolved['symbol']] = true;
    }

    private function classDocumentPath(LaravelContext $context, string $className): ?string
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $resolvedPath = realpath($filePath) ?: $filePath;

        return str_ends_with($resolvedPath, '.php') ? $context->relativeProjectPath($resolvedPath) : null;
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

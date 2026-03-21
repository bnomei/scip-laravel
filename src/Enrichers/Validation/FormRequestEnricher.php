<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Validation;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\FormRequestMetadataExtractor;
use Bnomei\ScipLaravel\Support\FormRequestRouteParameterFinder;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\RouteParameterContractInventoryBuilder;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_unique;
use function array_values;
use function class_exists;
use function enum_exists;
use function is_array;
use function is_dir;
use function is_string;
use function ksort;
use function ltrim;
use function sort;

final class FormRequestEnricher implements Enricher
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    /**
     * @var array<string, array{documentPath: ?string, startLine: int}>
     */
    private array $classLocationCache = [];

    public function __construct(
        private readonly FormRequestMetadataExtractor $extractor = new FormRequestMetadataExtractor(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly RouteParameterContractInventoryBuilder $routeParameterInventoryBuilder = new RouteParameterContractInventoryBuilder(),
        private readonly FormRequestRouteParameterFinder $routeParameterFinder = new FormRequestRouteParameterFinder(),
        ?ProjectPhpAnalysisCache $analysisCache = null,
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $symbols = [];
        $occurrences = [];
        $routeParameterInventory = $this->routeParameterInventoryBuilder->build($context);

        foreach ($this->formRequestFiles($context->projectRoot) as $filePath) {
            $metadata = $this->extractor->extract($filePath);

            if ($metadata === null) {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);
            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $metadata->className,
                $metadata->classLine,
            );
            $routeDocs = $this->routeDocumentation(
                $routeParameterInventory['byFormRequestClass'][$metadata->className] ?? [],
            );
            $classDocumentation = array_values(array_unique([
                ...$metadata->classDocumentation,
                ...$routeDocs,
            ]));
            sort($classDocumentation);

            if (is_string($classSymbol) && $classSymbol !== '' && $classDocumentation !== []) {
                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $classSymbol,
                    'documentation' => $classDocumentation,
                ]));
            }

            if ($metadata->rulesMethodLine === null || $metadata->rulesMethodDocumentation === []) {
                continue;
            }

            $methodSymbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                'rules',
                $metadata->rulesMethodLine,
            );

            if (!is_string($methodSymbol) || $methodSymbol === '') {
                continue;
            }

            $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $methodSymbol,
                'documentation' => array_values(array_unique([
                    ...$metadata->rulesMethodDocumentation,
                    ...$routeDocs,
                ])),
            ]));
        }

        foreach ($this->routeParameterFinder->find($routeParameterInventory['byFormRequestClass']) as $reference) {
            $documentPath = $context->relativeProjectPath($reference->filePath);
            $contract =
                $routeParameterInventory['byFormRequestClass'][$reference->className][$reference->parameterName]
                ?? null;

            if ($contract === null) {
                continue;
            }

            if ($reference->propertyShortcut && is_string($contract->boundClass) && $contract->boundClass !== '') {
                $boundLocation = $this->classLocation($context, $contract->boundClass);
                $boundDocumentPath = $boundLocation['documentPath'];
                $boundStartLine = $boundLocation['startLine'];
                $symbol = $boundDocumentPath === null
                    ? null
                    : $this->classSymbolResolver->resolve(
                        $context->baselineIndex,
                        $boundDocumentPath,
                        ltrim($contract->boundClass, '\\'),
                        $boundStartLine,
                    );

                if (is_string($symbol) && $symbol !== '') {
                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->range->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::Identifier,
                            'override_documentation' => ['Bound route target: ' . $contract->boundClass],
                        ]));
                }

                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $contract->symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
                'override_documentation' => ['Route parameter: ' . $reference->parameterName],
            ]));
        }

        return $symbols === [] && $occurrences === []
            ? IndexPatch::empty()
            : new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @return list<string>
     */
    private function formRequestFiles(string $projectRoot): array
    {
        $root = $projectRoot . '/app/Http/Requests';

        return is_dir($root) ? $this->analysisCache->phpFilesInRoots([$root]) : [];
    }

    /**
     * @return array{documentPath: ?string, startLine: int}
     */
    private function classLocation(LaravelContext $context, string $className): array
    {
        if (isset($this->classLocationCache[$className])) {
            return $this->classLocationCache[$className];
        }

        if (!class_exists($className) && !enum_exists($className)) {
            return $this->classLocationCache[$className] = [
                'documentPath' => null,
                'startLine' => 1,
            ];
        }

        $reflection = new \ReflectionClass($className);
        $filePath = $reflection->getFileName();

        return $this->classLocationCache[$className] = [
            'documentPath' => is_string($filePath) && $filePath !== ''
                ? $context->relativeProjectPath($filePath)
                : null,
            'startLine' => $reflection->getStartLine(),
        ];
    }

    /**
     * @param array<string, object> $contracts
     * @return list<string>
     */
    private function routeDocumentation(array $contracts): array
    {
        if ($contracts === []) {
            return [];
        }

        $labels = [];

        foreach ($contracts as $contract) {
            $name = $contract->parameterName ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            $label = $name;
            $types = $contract->types ?? [];

            if (is_array($types) && $types !== []) {
                $types = array_values(array_unique(array_filter(
                    $types,
                    static fn(mixed $value): bool => is_string($value) && $value !== '',
                )));
                sort($types);

                if ($types !== []) {
                    $label .= ': ' . implode('|', $types);
                }
            }

            if (is_string($contract->bindingKey ?? null) && $contract->bindingKey !== '') {
                $label .= ' (binding key: ' . $contract->bindingKey . ')';
            }

            $labels[] = $label;
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels === [] ? [] : ['Route parameters: ' . implode(', ', $labels)];
    }
}

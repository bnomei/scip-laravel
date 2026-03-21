<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Models;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\RouteBoundModelScopeInventoryBuilder;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\LiteralModelAttributeFinder;
use Bnomei\ScipLaravel\Support\PhpModelMemberReferenceFinder;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Support\SurveyorTypeFormatter;
use Bnomei\ScipLaravel\Support\VoltBladeModelMemberReferenceFinder;
use Bnomei\ScipLaravel\Symbols\LaravelSymbolNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Laravel\Ranger\Components\Model as RangerModel;
use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Types\ClassType;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function preg_match;
use function realpath;
use function sort;
use function str_replace;

final class ModelEnricher implements Enricher
{
    public function __construct(
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        private readonly LiteralModelAttributeFinder $literalAttributeFinder = new LiteralModelAttributeFinder(),
        private readonly PhpModelMemberReferenceFinder $referenceFinder = new PhpModelMemberReferenceFinder(),
        private readonly VoltBladeModelMemberReferenceFinder $bladeReferenceFinder = new VoltBladeModelMemberReferenceFinder(),
        private readonly RouteBoundModelScopeInventoryBuilder $routeBoundModelScopeInventoryBuilder = new RouteBoundModelScopeInventoryBuilder(),
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
        private readonly LaravelSymbolNormalizer $symbolNormalizer = new LaravelSymbolNormalizer(),
    ) {}

    public function feature(): string
    {
        return 'models';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $modelPayload = $this->modelPayload($context);

        if ($modelPayload['facts'] === [] && $modelPayload['symbolDocs'] === []) {
            return IndexPatch::empty();
        }

        $occurrences = [];

        foreach ($modelPayload['definitions'] as $payload) {
            $occurrences[] =
                new DocumentOccurrencePatch(documentPath: $payload['documentPath'], occurrence: new Occurrence([
                    'range' => $payload['range'],
                    'symbol' => $payload['symbol'],
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => $payload['syntaxKind'],
                ]));
        }

        $knownModels = array_fill_keys(array_keys($modelPayload['facts']), true);
        $routeBoundScopes = $this->routeBoundScopesByFile($context);
        $references = [
            ...$this->referenceFinder->find($context->projectRoot, $knownModels, $routeBoundScopes),
            ...$this->bladeReferenceFinder->find($context->projectRoot, $knownModels, $routeBoundScopes),
        ];
        $this->populateCallSymbols(
            $context,
            $modelPayload['facts'],
            $modelPayload['methodResolvers'],
            $this->referencedMethodNamesByModel($references),
        );

        foreach ($references as $reference) {
            $fact = $modelPayload['facts'][$reference->modelClass] ?? null;

            if (!is_array($fact)) {
                continue;
            }

            $targetSymbols = $reference->methodCall
                ? $fact['callSymbols']
                : ($reference->write ? $fact['writeSymbols'] : $fact['readSymbols']);
            $symbol = $targetSymbols[$reference->memberName] ?? null;

            if (!is_string($symbol) || $symbol === '') {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($reference->filePath),
                occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => $reference->write ? SymbolRole::WriteAccess : SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]),
            );
        }

        $symbols = [];
        ksort($modelPayload['symbolDocs']);

        foreach ($modelPayload['symbolDocs'] as $payload) {
            sort($payload['documentation']);
            $symbols[] = new DocumentSymbolPatch(documentPath: $payload['documentPath'], symbol: new SymbolInformation([
                'symbol' => $payload['symbol'],
                'documentation' => array_values(array_unique($payload['documentation'])),
            ]));
        }

        if ($symbols === [] && $occurrences === []) {
            return IndexPatch::empty();
        }

        return new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function routeBoundScopesByFile(LaravelContext $context): array
    {
        $inventory = $this->routeBoundModelScopeInventoryBuilder->collect($context);
        $scopesByFile = [];

        foreach ($inventory->scopesByDocumentPath as $documentPath => $scope) {
            $filePath =
                $context->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documentPath);
            $resolvedPath = realpath($filePath) ?: $filePath;
            $scopesByFile[$resolvedPath] = $scope;
        }

        ksort($scopesByFile);

        return $scopesByFile;
    }

    /**
     * @return array{
     *     facts: array<string, array{readSymbols: array<string, string>, writeSymbols: array<string, string>, callSymbols: array<string, string>}>,
     *     symbolDocs: array<string, array{documentPath: string, symbol: string, documentation: list<string>}>,
     *     definitions: array<string, array{documentPath: string, symbol: string, range: array{int, int, int, int}, syntaxKind: int}>,
     *     methodResolvers: array<string, array{reflection: \ReflectionClass, callableMethods: array<string, \ReflectionMethod>, methodPayloadCache: array<string, array{symbol: string, documentPath: string}|null>}>
     * }
     */
    private function modelPayload(LaravelContext $context): array
    {
        $facts = [];
        $symbolDocs = [];
        $definitions = [];
        $methodResolvers = [];
        $inspector = $this->modelInspector($context);
        $models = [];

        foreach ($context->rangerSnapshot->models as $model) {
            if ($model instanceof RangerModel) {
                $models[$model->name] = $model;
            }
        }

        ksort($models);

        foreach ($models as $className => $model) {
            try {
                $reflection = new \ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            $filePath = $reflection->getFileName();

            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            $relativePath = $context->relativeProjectPath($filePath);
            $classResult = $context->surveyor->class($className);
            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $relativePath,
                $className,
                $reflection->getStartLine(),
            );
            $declaredMethods = $this->declaredMethods($reflection);
            $callableMethods = $this->callableMethods($reflection);
            $declaredProperties = $this->declaredProperties($reflection);
            $attributeMetadata = $this->attributeMetadata($inspector, $className);
            $literalAttributes = $this->literalAttributeFinder->find($reflection);
            $attributeNames = $this->attributeNames(
                $model,
                $attributeMetadata,
                $literalAttributes,
                $this->inferredAttributeNames($declaredMethods, $classResult, $model->snakeCaseAttributes()),
            );
            $relationNames = $this->relationNames($model, $this->inferredRelationNames($declaredMethods, $classResult));
            $castNames = $this->castNames($attributeMetadata);
            $readSymbols = [];
            $writeSymbols = [];
            $callSymbols = [];
            $accessorAttributes = [];
            $mutatorAttributes = [];
            $memberAliasCache = [];
            $propertySymbolCache = [];
            $methodPayloadCache = [];
            $accessorCache = [];
            $mutatorCache = [];
            $attributeContractCache = [];
            $relationContractCache = [];

            foreach ($declaredProperties as $propertyName) {
                $propertySymbol =
                    $propertySymbolCache[$propertyName] ??= $this->propertySymbolResolver->resolve(
                        $context->baselineIndex,
                        $relativePath,
                        $className,
                        $propertyName,
                    );

                if (!is_string($propertySymbol) || $propertySymbol === '') {
                    continue;
                }

                $readSymbols[$propertyName] ??= $propertySymbol;
                $writeSymbols[$propertyName] ??= $propertySymbol;
            }

            foreach ($attributeNames as $attributeName) {
                $aliases = $this->memberAliasesCached($memberAliasCache, $attributeName, $model->snakeCaseAttributes());
                $attributeDocumentation =
                    $attributeContractCache[$attributeName] ??= $this->attributeContractDocumentation(
                        $model,
                        $attributeName,
                    );

                foreach ($aliases as $alias) {
                    $propertySymbol =
                        $propertySymbolCache[$alias] ??= $this->propertySymbolResolver->resolve(
                            $context->baselineIndex,
                            $relativePath,
                            $className,
                            $alias,
                        );

                    if (is_string($propertySymbol) && $propertySymbol !== '') {
                        $readSymbols[$alias] = $propertySymbol;
                        $writeSymbols[$alias] = $propertySymbol;

                        $this->addSymbolDocumentation(
                            $symbolDocs,
                            $relativePath,
                            $propertySymbol,
                            $attributeDocumentation,
                        );
                    }
                }

                $accessor = $this->accessorMethodSymbol(
                    $context,
                    $reflection,
                    $classResult,
                    $declaredMethods,
                    $attributeName,
                    $methodPayloadCache,
                    $accessorCache,
                );
                $mutator = $this->mutatorMethodSymbol(
                    $context,
                    $reflection,
                    $classResult,
                    $declaredMethods,
                    $attributeName,
                    $methodPayloadCache,
                    $mutatorCache,
                );

                if ($accessor !== null) {
                    $accessorAttributes[] = $attributeName;

                    foreach ($aliases as $alias) {
                        $readSymbols[$alias] ??= $accessor['symbol'];
                    }

                    $this->addSymbolDocumentation(
                        $symbolDocs,
                        $accessor['documentPath'],
                        $accessor['symbol'],
                        ['Laravel model accessor attribute: ' . $attributeName],
                    );
                }

                if ($mutator !== null) {
                    $mutatorAttributes[] = $attributeName;

                    foreach ($aliases as $alias) {
                        $writeSymbols[$alias] ??= $mutator['symbol'];
                    }

                    $this->addSymbolDocumentation(
                        $symbolDocs,
                        $mutator['documentPath'],
                        $mutator['symbol'],
                        ['Laravel model mutator attribute: ' . $attributeName],
                    );
                }

                $this->registerLiteralAttributeFallback(
                    definitions: $definitions,
                    symbolDocs: $symbolDocs,
                    readSymbols: $readSymbols,
                    writeSymbols: $writeSymbols,
                    documentPath: $relativePath,
                    className: $className,
                    attributeName: $attributeName,
                    aliases: $aliases,
                    definitionRange: $literalAttributes[$attributeName] ?? null,
                );
            }

            foreach ($relationNames as $relationName) {
                $propertySymbol =
                    $propertySymbolCache[$relationName] ??= $this->propertySymbolResolver->resolve(
                        $context->baselineIndex,
                        $relativePath,
                        $className,
                        $relationName,
                    );

                if (is_string($propertySymbol) && $propertySymbol !== '') {
                    $readSymbols[$relationName] = $propertySymbol;
                    continue;
                }

                $methodPayload = $this->methodSymbolPayload(
                    $context,
                    $reflection,
                    $declaredMethods,
                    $relationName,
                    $methodPayloadCache,
                );

                if ($methodPayload === null) {
                    continue;
                }

                $readSymbols[$relationName] = $methodPayload['symbol'];
                $relationDocumentation =
                    $relationContractCache[$relationName] ??= [
                        'Laravel model relation attribute: ' . $relationName,
                        ...$this->relationContractDocumentation($model, $relationName),
                    ];
                $this->addSymbolDocumentation(
                    $symbolDocs,
                    $methodPayload['documentPath'],
                    $methodPayload['symbol'],
                    $relationDocumentation,
                );
            }

            $castsCarrier = $this->castsCarrierSymbol($context, $reflection, $declaredMethods, $methodPayloadCache);

            if ($castsCarrier !== null && $castNames !== []) {
                $this->addSymbolDocumentation($symbolDocs, $castsCarrier['documentPath'], $castsCarrier['symbol'], [
                    'Laravel cast attributes: ' . implode(', ', $castNames),
                ]);
            }

            if ($classSymbol !== null) {
                $documentation = $this->classDocumentation(
                    $model,
                    $attributeNames,
                    $relationNames,
                    $castNames,
                    $accessorAttributes,
                    $mutatorAttributes,
                );

                if ($documentation !== []) {
                    $this->addSymbolDocumentation($symbolDocs, $relativePath, $classSymbol, $documentation);
                }
            }

            $facts[$className] = [
                'readSymbols' => $readSymbols,
                'writeSymbols' => $writeSymbols,
                'callSymbols' => $callSymbols,
            ];
            $methodResolvers[$className] = [
                'reflection' => $reflection,
                'callableMethods' => $callableMethods,
                'methodPayloadCache' => $methodPayloadCache,
            ];
        }

        return [
            'facts' => $facts,
            'symbolDocs' => $symbolDocs,
            'definitions' => $definitions,
            'methodResolvers' => $methodResolvers,
        ];
    }

    /**
     * @return array<string, \ReflectionMethod>
     */
    private function declaredMethods(\ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if (
                $method->getDeclaringClass()->getName() !== $reflection->getName()
                || $method->isConstructor()
                || $method->isDestructor()
                || $method->isStatic()
            ) {
                continue;
            }

            $methods[$method->getName()] = $method;
        }

        ksort($methods);

        return $methods;
    }

    /**
     * @return array<string, \ReflectionMethod>
     */
    private function callableMethods(\ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if (
                $method->getDeclaringClass()->getName() !== $reflection->getName()
                || $method->isConstructor()
                || $method->isDestructor()
            ) {
                continue;
            }

            $methods[$method->getName()] = $method;
        }

        ksort($methods);

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function declaredProperties(\ReflectionClass $reflection): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName() || $property->isStatic()) {
                continue;
            }

            $properties[] = $property->getName();
        }

        sort($properties);

        return $properties;
    }

    /**
     * @param list<\Bnomei\ScipLaravel\Support\PhpModelMemberReference> $references
     * @return array<string, list<string>>
     */
    private function referencedMethodNamesByModel(array $references): array
    {
        $methodsByModel = [];

        foreach ($references as $reference) {
            if (!$reference->methodCall || $reference->memberName === '') {
                continue;
            }

            $methodsByModel[$reference->modelClass][$reference->memberName] = true;
        }

        foreach ($methodsByModel as $className => $methods) {
            $methodNames = array_keys($methods);
            sort($methodNames);
            $methodsByModel[$className] = $methodNames;
        }

        ksort($methodsByModel);

        return $methodsByModel;
    }

    /**
     * @param array<string, array{readSymbols: array<string, string>, writeSymbols: array<string, string>, callSymbols: array<string, string>}> $facts
     * @param array<string, array{reflection: \ReflectionClass, callableMethods: array<string, \ReflectionMethod>, methodPayloadCache: array<string, array{symbol: string, documentPath: string}|null>}> $methodResolvers
     * @param array<string, list<string>> $referencedMethodNamesByModel
     */
    private function populateCallSymbols(
        LaravelContext $context,
        array &$facts,
        array $methodResolvers,
        array $referencedMethodNamesByModel,
    ): void {
        foreach ($referencedMethodNamesByModel as $className => $methodNames) {
            $fact = $facts[$className] ?? null;
            $resolver = $methodResolvers[$className] ?? null;

            if (!is_array($fact) || !is_array($resolver)) {
                continue;
            }

            $reflection = $resolver['reflection'];
            $callableMethods = $resolver['callableMethods'];
            $methodPayloadCache = $resolver['methodPayloadCache'];

            foreach ($methodNames as $methodName) {
                $methodPayload = $this->methodSymbolPayload(
                    $context,
                    $reflection,
                    $callableMethods,
                    $methodName,
                    $methodPayloadCache,
                );

                if ($methodPayload === null) {
                    continue;
                }

                $fact['callSymbols'][$methodName] = $methodPayload['symbol'];
            }

            ksort($fact['callSymbols']);
            $facts[$className] = $fact;
        }
    }

    private function modelInspector(LaravelContext $context): ?ModelInspector
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $inspector = $context->application->make(ModelInspector::class);
        } catch (Throwable) {
            return null;
        }

        return $inspector instanceof ModelInspector ? $inspector : null;
    }

    /**
     * @return array<string, array{cast: ?string}>
     */
    private function attributeMetadata(?ModelInspector $inspector, string $className): array
    {
        if (!$inspector instanceof ModelInspector) {
            return [];
        }

        try {
            $info = $inspector->inspect($className);
        } catch (Throwable) {
            return [];
        }

        $attributes = $info->attributes ?? null;

        if (!is_iterable($attributes)) {
            return [];
        }

        $metadata = [];

        foreach ($attributes as $attribute) {
            if (
                !is_array($attribute)
                || !isset($attribute['name'])
                || !is_string($attribute['name'])
                || $attribute['name'] === ''
            ) {
                continue;
            }

            $metadata[$attribute['name']] = [
                'cast' =>
                    isset($attribute['cast']) && is_string($attribute['cast']) && $attribute['cast'] !== ''
                        ? $attribute['cast']
                        : null,
            ];
        }

        ksort($metadata);

        return $metadata;
    }

    /**
     * @param array<string, array{cast: ?string}> $attributeMetadata
     * @param array<string, SourceRange> $literalAttributes
     * @param list<string> $inferredAttributeNames
     * @return list<string>
     */
    private function attributeNames(
        RangerModel $model,
        array $attributeMetadata,
        array $literalAttributes,
        array $inferredAttributeNames = [],
    ): array {
        $names = array_keys($model->getAttributes());

        foreach (array_keys($attributeMetadata) as $attributeName) {
            $names[] = $attributeName;
        }

        foreach (array_keys($literalAttributes) as $attributeName) {
            $names[] = $attributeName;
        }

        foreach ($inferredAttributeNames as $attributeName) {
            $names[] = $attributeName;
        }

        $names = array_values(array_unique(array_filter(
            $names,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )));
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $inferredRelationNames
     * @return list<string>
     */
    private function relationNames(RangerModel $model, array $inferredRelationNames = []): array
    {
        $names = array_values(array_filter(
            array_merge(array_keys($model->getRelations()), $inferredRelationNames),
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        ));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, array{cast: ?string}> $attributeMetadata
     * @return list<string>
     */
    private function castNames(array $attributeMetadata): array
    {
        $names = [];

        foreach ($attributeMetadata as $attributeName => $metadata) {
            if ($metadata['cast'] !== null && !in_array($metadata['cast'], ['accessor', 'attribute'], true)) {
                $names[] = $attributeName;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function memberAliases(string $attributeName, bool $snakeCaseAttributes): array
    {
        $aliases = [$attributeName];

        if ($snakeCaseAttributes) {
            $aliases[] = Str::snake($attributeName);
        } else {
            $aliases[] = Str::camel($attributeName);
        }

        $aliases = array_values(array_unique(array_filter(
            $aliases,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )));
        sort($aliases);

        return $aliases;
    }

    /**
     * @param array<string, list<string>> $cache
     * @return list<string>
     */
    private function memberAliasesCached(array &$cache, string $attributeName, bool $snakeCaseAttributes): array
    {
        $cacheKey = $attributeName . "\x1F" . ($snakeCaseAttributes ? '1' : '0');

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        return $cache[$cacheKey] = $this->memberAliases($attributeName, $snakeCaseAttributes);
    }

    /**
     * @param array<string, \ReflectionMethod> $declaredMethods
     * @return list<string>
     */
    private function inferredAttributeNames(
        array $declaredMethods,
        ?ClassResult $classResult,
        bool $snakeCaseAttributes,
    ): array {
        $names = [];

        foreach ($declaredMethods as $methodName => $method) {
            if ($this->isAttributeMethod($method, $classResult, $methodName)) {
                $canonical = Str::camel($methodName);
                $names[] = $snakeCaseAttributes ? Str::snake($canonical) : $canonical;
                continue;
            }

            if (preg_match('/^(?:get|set)(.+)Attribute$/', $methodName, $matches) !== 1) {
                continue;
            }

            $canonical = Str::camel($matches[1] ?? '');

            if ($canonical === '') {
                continue;
            }

            $names[] = $snakeCaseAttributes ? Str::snake($canonical) : $canonical;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, \ReflectionMethod> $declaredMethods
     * @return list<string>
     */
    private function inferredRelationNames(array $declaredMethods, ?ClassResult $classResult): array
    {
        $names = [];

        foreach ($declaredMethods as $methodName => $method) {
            if ($this->isRelationMethod($method, $classResult, $methodName)) {
                $names[] = $methodName;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, array{documentPath: string, symbol: string, documentation: list<string>}> $symbolDocs
     * @param array<string, array{documentPath: string, symbol: string, range: array{int, int, int, int}, syntaxKind: int}> $definitions
     * @param array<string, string> $readSymbols
     * @param array<string, string> $writeSymbols
     * @param list<string> $aliases
     */
    private function registerLiteralAttributeFallback(
        array &$definitions,
        array &$symbolDocs,
        array &$readSymbols,
        array &$writeSymbols,
        string $documentPath,
        string $className,
        string $attributeName,
        array $aliases,
        ?SourceRange $definitionRange,
    ): void {
        if (!$definitionRange instanceof SourceRange) {
            return;
        }

        $needsFallback = false;

        foreach ($aliases as $alias) {
            if (!isset($readSymbols[$alias]) || !isset($writeSymbols[$alias])) {
                $needsFallback = true;
                break;
            }
        }

        if (!$needsFallback) {
            return;
        }

        $symbol = $this->symbolNormalizer->modelAttribute($className, $attributeName);

        if (!is_string($symbol) || $symbol === '') {
            return;
        }

        foreach ($aliases as $alias) {
            $readSymbols[$alias] ??= $symbol;
            $writeSymbols[$alias] ??= $symbol;
        }

        $definitionKey = $documentPath . ':' . $symbol;
        $definitions[$definitionKey] = [
            'documentPath' => $documentPath,
            'symbol' => $symbol,
            'range' => $definitionRange->toScipRange(),
            'syntaxKind' => SyntaxKind::StringLiteralKey,
        ];

        $this->addSymbolDocumentation(
            $symbolDocs,
            $documentPath,
            $symbol,
            ['Laravel model literal row attribute: ' . $attributeName],
        );
    }

    /**
     * @return array{symbol: string, documentPath: string}|null
     */
    private function accessorMethodSymbol(
        LaravelContext $context,
        \ReflectionClass $reflection,
        ?ClassResult $classResult,
        array $declaredMethods,
        string $attributeName,
        array &$methodPayloadCache,
        array &$accessorCache,
    ): ?array {
        if (array_key_exists($attributeName, $accessorCache)) {
            return $accessorCache[$attributeName];
        }

        $studly = Str::studly($attributeName);
        $legacy = 'get' . $studly . 'Attribute';

        if (isset($declaredMethods[$legacy])) {
            return $accessorCache[$attributeName] = $this->methodSymbolPayload(
                $context,
                $reflection,
                $declaredMethods,
                $legacy,
                $methodPayloadCache,
            );
        }

        $camel = Str::camel($attributeName);

        if (!isset($declaredMethods[$camel])) {
            return $accessorCache[$attributeName] = null;
        }

        if (!$this->isAttributeMethod($declaredMethods[$camel], $classResult, $camel)) {
            return $accessorCache[$attributeName] = null;
        }

        return $accessorCache[$attributeName] = $this->methodSymbolPayload(
            $context,
            $reflection,
            $declaredMethods,
            $camel,
            $methodPayloadCache,
        );
    }

    /**
     * @return array{symbol: string, documentPath: string}|null
     */
    private function mutatorMethodSymbol(
        LaravelContext $context,
        \ReflectionClass $reflection,
        ?ClassResult $classResult,
        array $declaredMethods,
        string $attributeName,
        array &$methodPayloadCache,
        array &$mutatorCache,
    ): ?array {
        if (array_key_exists($attributeName, $mutatorCache)) {
            return $mutatorCache[$attributeName];
        }

        $studly = Str::studly($attributeName);
        $legacy = 'set' . $studly . 'Attribute';

        if (isset($declaredMethods[$legacy])) {
            return $mutatorCache[$attributeName] = $this->methodSymbolPayload(
                $context,
                $reflection,
                $declaredMethods,
                $legacy,
                $methodPayloadCache,
            );
        }

        $camel = Str::camel($attributeName);

        if (!isset($declaredMethods[$camel])) {
            return $mutatorCache[$attributeName] = null;
        }

        if (!$this->isAttributeMethod($declaredMethods[$camel], $classResult, $camel)) {
            return $mutatorCache[$attributeName] = null;
        }

        return $mutatorCache[$attributeName] = $this->methodSymbolPayload(
            $context,
            $reflection,
            $declaredMethods,
            $camel,
            $methodPayloadCache,
        );
    }

    private function isAttributeMethod(\ReflectionMethod $method, ?ClassResult $classResult, string $methodName): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof \ReflectionNamedType && ltrim($returnType->getName(), '\\') === Attribute::class) {
            return true;
        }

        if ($classResult === null || !$classResult->hasMethod($methodName)) {
            return false;
        }

        foreach ($classResult->getMethod($methodName)->returnTypes() as $returnTypePayload) {
            $type = $returnTypePayload['type'] ?? null;

            if ($type instanceof ClassType && $type->resolved() === Attribute::class) {
                return true;
            }
        }

        return false;
    }

    private function isRelationMethod(\ReflectionMethod $method, ?ClassResult $classResult, string $methodName): bool
    {
        $returnType = $method->getReturnType();

        if (
            $returnType instanceof \ReflectionNamedType
            && !$returnType->isBuiltin()
            && is_a(ltrim($returnType->getName(), '\\'), Relation::class, true)
        ) {
            return true;
        }

        if ($classResult === null || !$classResult->hasMethod($methodName)) {
            return false;
        }

        foreach ($classResult->getMethod($methodName)->returnTypes() as $returnTypePayload) {
            $type = $returnTypePayload['type'] ?? null;

            if ($type instanceof ClassType && is_a($type->resolved(), Relation::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{symbol: string, documentPath: string}|null
     */
    private function methodSymbolPayload(
        LaravelContext $context,
        \ReflectionClass $reflection,
        array $declaredMethods,
        string $methodName,
        array &$cache,
    ): ?array {
        if (array_key_exists($methodName, $cache)) {
            return $cache[$methodName];
        }

        if (!isset($declaredMethods[$methodName])) {
            return $cache[$methodName] = null;
        }

        $method = $declaredMethods[$methodName];

        $filePath = $method->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $relativePath = $context->relativeProjectPath($filePath);
        $symbol = $this->methodSymbolResolver->resolve(
            $context->baselineIndex,
            $relativePath,
            $methodName,
            $method->getStartLine(),
        );

        if (!is_string($symbol) || $symbol === '') {
            return $cache[$methodName] = null;
        }

        return $cache[$methodName] = [
            'symbol' => $symbol,
            'documentPath' => $relativePath,
        ];
    }

    /**
     * @return array{symbol: string, documentPath: string}|null
     */
    private function castsCarrierSymbol(
        LaravelContext $context,
        \ReflectionClass $reflection,
        array $declaredMethods,
        array &$methodPayloadCache,
    ): ?array {
        if (isset($declaredMethods['casts'])) {
            return $this->methodSymbolPayload($context, $reflection, $declaredMethods, 'casts', $methodPayloadCache);
        }

        if (!$reflection->hasProperty('casts')) {
            return null;
        }

        try {
            $property = $reflection->getProperty('casts');
        } catch (Throwable) {
            return null;
        }

        $declaringClass = $property->getDeclaringClass();
        $filePath = $declaringClass->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $relativePath = $context->relativeProjectPath($filePath);
        $symbol = $this->propertySymbolResolver->resolve(
            $context->baselineIndex,
            $relativePath,
            $declaringClass->getName(),
            'casts',
        );

        if (!is_string($symbol) || $symbol === '') {
            return null;
        }

        return [
            'symbol' => $symbol,
            'documentPath' => $relativePath,
        ];
    }

    /**
     * @param list<string> $attributeNames
     * @param list<string> $relationNames
     * @param list<string> $castNames
     * @param list<string> $accessorAttributes
     * @param list<string> $mutatorAttributes
     * @return list<string>
     */
    private function classDocumentation(
        RangerModel $model,
        array $attributeNames,
        array $relationNames,
        array $castNames,
        array $accessorAttributes,
        array $mutatorAttributes,
    ): array {
        $documentation = [];

        if ($attributeNames !== []) {
            $documentation[] = 'Laravel model attributes: ' . implode(', ', $attributeNames);
        }

        if ($relationNames !== []) {
            $documentation[] = 'Laravel model relations: ' . implode(', ', $relationNames);
        }

        $typedAttributes = [];

        foreach ($model->getAttributes() as $name => $type) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $typedAttributes[] = $name . ': ' . $this->typeFormatter->format($type);
        }

        $typedAttributes = array_values(array_unique($typedAttributes));
        sort($typedAttributes);

        if ($typedAttributes !== []) {
            $documentation[] = 'Laravel model attribute contracts: ' . implode(', ', $typedAttributes);
        }

        $typedRelations = [];

        foreach ($model->getRelations() as $name => $type) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $typedRelations[] = $name . ': ' . $this->typeFormatter->format($type);
        }

        $typedRelations = array_values(array_unique($typedRelations));
        sort($typedRelations);

        if ($typedRelations !== []) {
            $documentation[] = 'Laravel model relation contracts: ' . implode(', ', $typedRelations);
        }

        $documentation[] = $model->snakeCaseAttributes()
            ? 'Laravel model snake_case attribute aliases: enabled'
            : 'Laravel model snake_case attribute aliases: disabled';

        if ($castNames !== []) {
            $documentation[] = 'Laravel cast attributes: ' . implode(', ', $castNames);
        }

        $accessorAttributes = array_values(array_unique($accessorAttributes));
        sort($accessorAttributes);

        if ($accessorAttributes !== []) {
            $documentation[] = 'Laravel accessor attributes: ' . implode(', ', $accessorAttributes);
        }

        $mutatorAttributes = array_values(array_unique($mutatorAttributes));
        sort($mutatorAttributes);

        if ($mutatorAttributes !== []) {
            $documentation[] = 'Laravel mutator attributes: ' . implode(', ', $mutatorAttributes);
        }

        return $documentation;
    }

    /**
     * @return list<string>
     */
    private function attributeContractDocumentation(RangerModel $model, string $attributeName): array
    {
        $type = $model->getAttributes()[$attributeName] ?? null;

        if (!is_object($type)) {
            return [];
        }

        return ['Laravel model attribute contract: ' . $attributeName . ': ' . $this->typeFormatter->format($type)];
    }

    /**
     * @return list<string>
     */
    private function relationContractDocumentation(RangerModel $model, string $relationName): array
    {
        $type = $model->getRelations()[$relationName] ?? null;

        if (!is_object($type)) {
            return [];
        }

        return ['Laravel model relation contract: ' . $relationName . ': ' . $this->typeFormatter->format($type)];
    }

    /**
     * @param array<string, array{documentPath: string, symbol: string, documentation: list<string>}> $symbolDocs
     * @param list<string> $documentation
     */
    private function addSymbolDocumentation(
        array &$symbolDocs,
        string $documentPath,
        string $symbol,
        array $documentation,
    ): void {
        if ($documentation === []) {
            return;
        }

        $key = $documentPath . "\n" . $symbol;

        if (!isset($symbolDocs[$key])) {
            $symbolDocs[$key] = [
                'documentPath' => $documentPath,
                'symbol' => $symbol,
                'documentation' => [],
            ];
        }

        $symbolDocs[$key]['documentation'] = array_values(array_unique(array_merge(
            $symbolDocs[$key]['documentation'],
            $documentation,
        )));
    }
}

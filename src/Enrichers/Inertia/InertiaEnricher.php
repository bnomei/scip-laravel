<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Inertia;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\PhpDeclaredClass;
use Bnomei\ScipLaravel\Support\PhpDeclaredClassFinder;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Support\SurveyorTypeFormatter;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Ranger\Components\InertiaSharedData;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\Contracts\Type as SurveyorType;
use Laravel\Surveyor\Types\UnionType;
use Scip\Document;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function file_get_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function preg_match;
use function realpath;
use function sort;
use function stat;
use function str_ends_with;
use function str_replace;
use function trim;

final class InertiaEnricher implements Enricher
{
    /**
     * @var array<string, list<string>>
     */
    private array $componentPathCache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $componentDocumentationCache = [];

    /**
     * @var array<string, array<string, array{types: list<string>, optional: bool}>>
     */
    private array $componentContractCache = [];

    /**
     * @var array<string, array<string, array{types: list<string>, optional: bool}>>
     */
    private array $sharedContractCache = [];

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly PhpDeclaredClassFinder $classFinder = new PhpDeclaredClassFinder(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
    ) {}

    public function feature(): string
    {
        return 'inertia';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $this->componentPathCache = [];
        $this->componentDocumentationCache = [];
        $this->componentContractCache = [];
        $this->sharedContractCache = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $sharedDocumentation = $this->sharedDataDocumentation($context);
        $documentationByComponent = $this->componentDocumentationByComponent($context);
        $componentContracts = $this->componentContractsByComponent($context);
        $sharedContracts = $this->sharedContracts($context);
        $inertiaRenderCalls = $this->inertiaRenderCalls($context->projectRoot);
        $components = $this->candidateComponents($context, $inertiaRenderCalls);
        $ownerSymbols = $this->sharedDataOwnerSymbols($context, $sharedDocumentation);
        $sharedContractOwners = $this->sharedDataContractOwners($context);

        if ($components === [] && $ownerSymbols === [] && $sharedContracts === []) {
            return IndexPatch::empty();
        }

        $documentsByPath = [];
        $symbolsByComponent = [];
        $componentOwners = [];
        $references = [];

        foreach ($components as $component) {
            $paths = $this->componentPaths($context->projectRoot, $component);

            if (count($paths) !== 1) {
                continue;
            }

            $path = $paths[0];
            $contents = file_get_contents($path);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $range = $this->definitionRange($contents);

            if ($range === null) {
                continue;
            }

            $symbol = $normalizer->inertia($component);
            $relativePath = $context->relativeProjectPath($path);
            $symbolsByComponent[$component] = $symbol;
            $componentOwners[$component] = [
                'documentPath' => $relativePath,
                'symbol' => $symbol,
                'range' => $range->toScipRange(),
            ];

            if (!isset($documentsByPath[$relativePath])) {
                $documentsByPath[$relativePath] = [
                    'language' => $this->componentLanguage($path),
                    'relative_path' => $relativePath,
                    'symbols' => [],
                    'occurrences' => [],
                    'text' => $contents,
                ];
            }

            $documentsByPath[$relativePath]['symbols'][$symbol] = new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $component,
                'kind' => Kind::File,
                'documentation' => $documentationByComponent[$component] ?? [],
            ]);
            $documentsByPath[$relativePath]['occurrences'][$symbol] = new Occurrence([
                'range' => $range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::Identifier,
            ]);
        }

        $contractSymbols = [];

        foreach ($componentOwners as $component => $owner) {
            foreach ($componentContracts[$component] ?? [] as $path => $contract) {
                $contractSymbol = $normalizer->domainSymbol('inertia-prop', $component . '::' . $path);
                $contractSymbols[] =
                    new DocumentSymbolPatch(documentPath: $owner['documentPath'], symbol: new SymbolInformation([
                        'symbol' => $contractSymbol,
                        'display_name' => $path,
                        'kind' => Kind::Property,
                        'documentation' => $this->contractDocumentation('Inertia prop', $path, $contract),
                        'enclosing_symbol' => $owner['symbol'],
                    ]));
                $references[] =
                    new DocumentOccurrencePatch(documentPath: $owner['documentPath'], occurrence: new Occurrence([
                        'range' => $owner['range'],
                        'symbol' => $contractSymbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                    ]));
            }
        }

        foreach ($sharedContracts as $path => $contract) {
            $contractSymbol = $normalizer->domainSymbol('inertia-shared', $path);

            foreach ($sharedContractOwners as $owner) {
                $contractSymbols[] =
                    new DocumentSymbolPatch(documentPath: $owner['documentPath'], symbol: new SymbolInformation([
                        'symbol' => $contractSymbol,
                        'display_name' => $path,
                        'kind' => Kind::Key,
                        'documentation' => $this->contractDocumentation('Inertia shared data', $path, $contract),
                        'enclosing_symbol' => $owner['symbol'],
                    ]));
                $references[] =
                    new DocumentOccurrencePatch(documentPath: $owner['documentPath'], occurrence: new Occurrence([
                        'range' => $owner['range'],
                        'symbol' => $contractSymbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                    ]));
            }

            foreach ($componentOwners as $owner) {
                $references[] =
                    new DocumentOccurrencePatch(documentPath: $owner['documentPath'], occurrence: new Occurrence([
                        'range' => $owner['range'],
                        'symbol' => $contractSymbol,
                        'symbol_roles' => SymbolRole::ReadAccess,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                    ]));
            }
        }

        if ($documentsByPath === [] && $ownerSymbols === [] && $contractSymbols === []) {
            return $references === []
                ? IndexPatch::empty()
                : new IndexPatch(symbols: $ownerSymbols, occurrences: $references);
        }

        foreach ($inertiaRenderCalls as $call) {
            if (!array_key_exists($call->literal, $symbolsByComponent)) {
                continue;
            }

            $references[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByComponent[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]),
            );
        }

        sort($components);
        ksort($documentsByPath);
        $documents = [];

        foreach ($documentsByPath as $document) {
            $documents[] = new Document([
                'language' => $document['language'],
                'relative_path' => $document['relative_path'],
                'symbols' => array_values($document['symbols']),
                'occurrences' => array_values($document['occurrences']),
                'text' => $document['text'],
            ]);
        }

        return new IndexPatch(
            symbols: [...$ownerSymbols, ...$contractSymbols],
            documents: $documents,
            occurrences: $references,
        );
    }

    /**
     * @return list<string>
     */
    private function candidateComponents(LaravelContext $context, array $inertiaRenderCalls): array
    {
        $components = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!is_object($route) || !method_exists($route, 'possibleResponses')) {
                continue;
            }

            foreach ($route->possibleResponses() as $response) {
                $component = $response instanceof InertiaResponse
                    ? $response->component
                    : (is_string($response) ? $response : '');

                if (trim($component) !== '') {
                    $components[$component] = true;
                }
            }
        }

        foreach (array_keys($context->rangerSnapshot->inertiaComponents) as $component) {
            if ($component !== '') {
                $components[$component] = true;
            }
        }

        foreach ($inertiaRenderCalls as $call) {
            if ($call->literal !== '') {
                $components[$call->literal] = true;
            }
        }

        $components = array_keys($components);
        sort($components);

        return $components;
    }

    /**
     * @return list<\Bnomei\ScipLaravel\Support\PhpLiteralCall>
     */
    private function inertiaRenderCalls(string $projectRoot): array
    {
        return $this->callFinder->find(
            $projectRoot,
            ['inertia'],
            [
                'Inertia\\Inertia' => ['render'],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function sharedDataDocumentation(LaravelContext $context): array
    {
        $keys = [];
        $typed = [];
        $withAllErrors = false;

        foreach ($context->rangerSnapshot->inertiaSharedData as $item) {
            if (!$item instanceof InertiaSharedData) {
                continue;
            }

            if ($item->withAllErrors) {
                $withAllErrors = true;
            }

            foreach ($item->data->keys() as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }

            foreach ($item->data->value as $key => $type) {
                if (!is_string($key) || $key === '' || !$type instanceof SurveyorType) {
                    continue;
                }

                $typed[$key][] = $this->typeFormatter->format($type);
            }
        }

        $documentation = [];

        if ($keys !== []) {
            $names = array_keys($keys);
            sort($names);
            $documentation[] = 'Inertia shared data keys: ' . implode(', ', $names);
        }

        if ($typed !== []) {
            $parts = [];
            ksort($typed);

            foreach ($typed as $key => $types) {
                $types = array_values(array_unique($types));
                sort($types);
                $parts[] = $key . ': ' . implode('|', $types);
            }

            if ($parts !== []) {
                $documentation[] = 'Inertia shared data types: ' . implode(', ', $parts);
            }
        }

        if ($withAllErrors) {
            $documentation[] = 'Inertia middleware shares all validation errors.';
        }

        return $documentation;
    }

    /**
     * @return array<string, list<string>>
     */
    private function componentDocumentationByComponent(LaravelContext $context): array
    {
        $cacheKey = $context->projectRoot;

        if (isset($this->componentDocumentationCache[$cacheKey])) {
            return $this->componentDocumentationCache[$cacheKey];
        }

        $sharedDocumentation = $this->sharedDataDocumentation($context);
        $documentationByComponent = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!is_object($route) || !method_exists($route, 'possibleResponses')) {
                continue;
            }

            foreach ($route->possibleResponses() as $response) {
                if (!$response instanceof InertiaResponse || trim($response->component) === '') {
                    continue;
                }

                $documentationByComponent[$response->component] ??= $sharedDocumentation;
                $expectedProps = $this->expectedPropsDocumentation($response);

                if ($expectedProps !== null) {
                    $documentationByComponent[$response->component][] = $expectedProps;
                }
            }
        }

        foreach ($context->rangerSnapshot->inertiaComponents as $component => $response) {
            if (!is_string($component) || $component === '' || !$response instanceof InertiaResponse) {
                continue;
            }

            $documentationByComponent[$component] ??= $sharedDocumentation;
            $expectedProps = $this->expectedPropsDocumentation($response);

            if ($expectedProps !== null) {
                $documentationByComponent[$component][] = $expectedProps;
            }
        }

        foreach ($documentationByComponent as $component => $docs) {
            $documentationByComponent[$component] = array_values(array_unique($docs));
            sort($documentationByComponent[$component]);
        }

        ksort($documentationByComponent);

        return $this->componentDocumentationCache[$cacheKey] = $documentationByComponent;
    }

    /**
     * @return array<string, array<string, array{types: list<string>, optional: bool}>>
     */
    private function componentContractsByComponent(LaravelContext $context): array
    {
        $cacheKey = $context->projectRoot;

        if (isset($this->componentContractCache[$cacheKey])) {
            return $this->componentContractCache[$cacheKey];
        }

        $contracts = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!is_object($route) || !method_exists($route, 'possibleResponses')) {
                continue;
            }

            foreach ($route->possibleResponses() as $response) {
                if (!$response instanceof InertiaResponse || trim($response->component) === '') {
                    continue;
                }

                $contracts[$response->component] ??= [];
                $this->mergeContracts($contracts[$response->component], $this->flattenResponseContracts($response));
            }
        }

        foreach ($context->rangerSnapshot->inertiaComponents as $component => $response) {
            if (!is_string($component) || $component === '' || !$response instanceof InertiaResponse) {
                continue;
            }

            $contracts[$component] ??= [];
            $this->mergeContracts($contracts[$component], $this->flattenResponseContracts($response));
        }

        foreach ($contracts as $component => $payload) {
            ksort($payload);
            $contracts[$component] = $payload;
        }

        ksort($contracts);

        return $this->componentContractCache[$cacheKey] = $contracts;
    }

    /**
     * @return array<string, array{types: list<string>, optional: bool}>
     */
    private function sharedContracts(LaravelContext $context): array
    {
        $cacheKey = $context->projectRoot;

        if (isset($this->sharedContractCache[$cacheKey])) {
            return $this->sharedContractCache[$cacheKey];
        }

        $contracts = [];

        foreach ($context->rangerSnapshot->inertiaSharedData as $item) {
            if (!$item instanceof InertiaSharedData) {
                continue;
            }

            $this->mergeContracts($contracts, $this->flattenAssociativeContracts($item->data->value));
        }

        ksort($contracts);

        return $this->sharedContractCache[$cacheKey] = $contracts;
    }

    /**
     * @param list<string> $sharedDocumentation
     * @return list<DocumentSymbolPatch>
     */
    private function sharedDataOwnerSymbols(LaravelContext $context, array $sharedDocumentation): array
    {
        if ($sharedDocumentation === []) {
            return [];
        }

        $appRoot = $context->projectPath('app');

        if (!is_dir($appRoot)) {
            return [];
        }

        $patches = [];

        foreach ($this->classFinder->findInRoots([$appRoot]) as $declaration) {
            if (
                !$declaration instanceof PhpDeclaredClass
                || !is_subclass_of($declaration->className, \Inertia\Middleware::class)
            ) {
                continue;
            }

            $reflection = new \ReflectionClass($declaration->className);
            $documentPath = $context->relativeProjectPath($declaration->filePath);
            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $declaration->className,
                $declaration->lineNumber,
            );

            if (is_string($classSymbol) && $classSymbol !== '') {
                $patches[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $classSymbol,
                    'documentation' => $sharedDocumentation,
                ]));
            }

            if (
                !$reflection->hasMethod('share')
                || $reflection->getMethod('share')->getDeclaringClass()->getName() !== $declaration->className
            ) {
                continue;
            }

            $methodSymbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                'share',
                $reflection->getMethod('share')->getStartLine(),
            );

            if (!is_string($methodSymbol) || $methodSymbol === '') {
                continue;
            }

            $patches[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $methodSymbol,
                'documentation' => $sharedDocumentation,
            ]));
        }

        return $patches;
    }

    /**
     * @return list<array{documentPath: string, symbol: string, range: array{int, int, int, int}}>
     */
    private function sharedDataContractOwners(LaravelContext $context): array
    {
        $appRoot = $context->projectPath('app');

        if (!is_dir($appRoot)) {
            return [];
        }

        $owners = [];

        foreach ($this->classFinder->findInRoots([$appRoot]) as $declaration) {
            if (
                !$declaration instanceof PhpDeclaredClass
                || !is_subclass_of($declaration->className, \Inertia\Middleware::class)
            ) {
                continue;
            }

            $reflection = new \ReflectionClass($declaration->className);
            $documentPath = $context->relativeProjectPath($declaration->filePath);
            $contents = file_get_contents($declaration->filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            if (
                $reflection->hasMethod('share')
                && $reflection->getMethod('share')->getDeclaringClass()->getName() === $declaration->className
            ) {
                $method = $reflection->getMethod('share');
                $methodSymbol = $this->methodSymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    'share',
                    $method->getStartLine(),
                );

                if (is_string($methodSymbol) && $methodSymbol !== '') {
                    $range = $this->lineDefinitionRange($contents, $method->getStartLine());

                    if ($range !== null) {
                        $owners[] = [
                            'documentPath' => $documentPath,
                            'symbol' => $methodSymbol,
                            'range' => $range->toScipRange(),
                        ];
                        continue;
                    }
                }
            }

            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $declaration->className,
                $declaration->lineNumber,
            );

            if (!is_string($classSymbol) || $classSymbol === '') {
                continue;
            }

            $range = $this->lineDefinitionRange($contents, $declaration->lineNumber);

            if ($range === null) {
                continue;
            }

            $owners[] = [
                'documentPath' => $documentPath,
                'symbol' => $classSymbol,
                'range' => $range->toScipRange(),
            ];
        }

        return $owners;
    }

    private function expectedPropsDocumentation(InertiaResponse $response): ?string
    {
        if ($response->data === []) {
            return null;
        }

        $parts = [];
        $props = $response->data;
        ksort($props);

        foreach ($props as $name => $type) {
            if (!is_string($name) || $name === '' || !$type instanceof SurveyorType) {
                continue;
            }

            $parts[] = $name . ($type->isOptional() ? '?' : '') . ': ' . $this->typeFormatter->format($type);
        }

        if ($parts === []) {
            return null;
        }

        return 'Inertia expected props: ' . implode(', ', $parts);
    }

    /**
     * @return list<string>
     */
    private function componentPaths(string $projectRoot, string $component): array
    {
        $cacheKey = $projectRoot . "\x1F" . $component;

        if (isset($this->componentPathCache[$cacheKey])) {
            return $this->componentPathCache[$cacheKey];
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($component, '/\\'));
        $paths = [];

        foreach ($this->componentRoots($projectRoot) as $root) {
            foreach (['.vue', '.tsx', '.ts', '.jsx', '.js'] as $extension) {
                $path = $root . DIRECTORY_SEPARATOR . $relative . $extension;
                $resolved = realpath($path) ?: $path;

                if (is_file($resolved)) {
                    $paths[$this->filesystemIdentity($resolved)] = $resolved;
                }
            }
        }

        $paths = array_values($paths);
        sort($paths);

        return $this->componentPathCache[$cacheKey] = $paths;
    }

    /**
     * @return list<string>
     */
    private function componentRoots(string $projectRoot): array
    {
        $roots = [];

        foreach ([
            $projectRoot . DIRECTORY_SEPARATOR . 'resources/js/Pages',
            $projectRoot . DIRECTORY_SEPARATOR . 'resources/js/pages',
            $projectRoot . DIRECTORY_SEPARATOR . 'resources/ts/Pages',
            $projectRoot . DIRECTORY_SEPARATOR . 'resources/ts/pages',
        ] as $root) {
            $resolved = realpath($root) ?: $root;

            if (is_file($resolved)) {
                continue;
            }

            if (is_dir($resolved)) {
                $roots[$this->filesystemIdentity($resolved)] ??= $resolved;
            }
        }

        $roots = array_values($roots);
        sort($roots);

        return $roots;
    }

    private function filesystemIdentity(string $path): string
    {
        $stat = stat($path);

        if (is_array($stat) && isset($stat['dev'], $stat['ino'])) {
            return $stat['dev'] . ':' . $stat['ino'];
        }

        return $path;
    }

    private function definitionRange(string $contents): ?SourceRange
    {
        $matched = preg_match('/\S/', $contents, $matches, PREG_OFFSET_CAPTURE);

        if ($matched !== 1) {
            return null;
        }

        $offset = $matches[0][1] ?? null;

        if (!is_int($offset)) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $offset, $offset + 1);
    }

    private function componentLanguage(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.vue') => 'vue',
            str_ends_with($path, '.ts'), str_ends_with($path, '.tsx') => 'typescript',
            default => 'javascript',
        };
    }

    /**
     * @param array<string, SurveyorType> $data
     * @return array<string, array{types: list<string>, optional: bool}>
     */
    private function flattenAssociativeContracts(array $data): array
    {
        $contracts = [];

        foreach ($data as $key => $type) {
            if (!is_string($key) || $key === '' || !$type instanceof SurveyorType) {
                continue;
            }

            $this->mergeContracts($contracts, $this->flattenTypeContracts($key, $type));
        }

        ksort($contracts);

        return $contracts;
    }

    /**
     * @return array<string, array{types: list<string>, optional: bool}>
     */
    private function flattenResponseContracts(InertiaResponse $response): array
    {
        return $this->flattenAssociativeContracts($response->data);
    }

    /**
     * @return array<string, array{types: list<string>, optional: bool}>
     */
    private function flattenTypeContracts(string $path, SurveyorType $type): array
    {
        if ($path === '') {
            return [];
        }

        if ($type instanceof ArrayType && !$type->isList() && $type->value !== []) {
            $contracts = [];

            foreach ($type->value as $key => $childType) {
                if (!is_string($key) || $key === '' || !$childType instanceof SurveyorType) {
                    continue;
                }

                $this->mergeContracts($contracts, $this->flattenTypeContracts($path . '.' . $key, $childType));
            }

            if ($contracts !== []) {
                return $contracts;
            }
        }

        if ($type instanceof UnionType) {
            $contracts = [];
            $arrayMembers = 0;

            foreach ($type->types as $member) {
                if (!$member instanceof SurveyorType) {
                    continue;
                }

                if ($member instanceof ArrayType && !$member->isList() && $member->value !== []) {
                    $arrayMembers++;
                    $this->mergeContracts($contracts, $this->flattenTypeContracts($path, $member));
                }
            }

            if ($contracts !== [] && $arrayMembers > 0) {
                return $contracts;
            }
        }

        $formatted = $this->typeFormatter->format($type);

        if ($formatted === '') {
            return [];
        }

        return [
            $path => [
                'types' => [$formatted],
                'optional' => $type->isOptional(),
            ],
        ];
    }

    /**
     * @param array<string, array{types: list<string>, optional: bool}> $existing
     * @param array<string, array{types: list<string>, optional: bool}> $incoming
     */
    private function mergeContracts(array &$existing, array $incoming): void
    {
        foreach ($incoming as $path => $contract) {
            $existing[$path]['types'] ??= [];
            $existing[$path]['optional'] ??= false;
            $existing[$path]['types'] = array_values(array_unique([
                ...$existing[$path]['types'],
                ...$contract['types'],
            ]));
            sort($existing[$path]['types']);
            $existing[$path]['optional'] = $existing[$path]['optional'] || $contract['optional'];
        }
    }

    /**
     * @param array{types: list<string>, optional: bool} $contract
     * @return list<string>
     */
    private function contractDocumentation(string $prefix, string $path, array $contract): array
    {
        $label = $path . ($contract['optional'] ? '?' : '');
        $typeLabel = $contract['types'] === [] ? 'mixed' : implode('|', $contract['types']);

        return [
            $prefix . ': ' . $label,
            'Surveyor type: ' . $typeLabel,
        ];
    }

    private function lineDefinitionRange(string $contents, int $lineNumber): ?SourceRange
    {
        if ($lineNumber < 1) {
            return null;
        }

        $lines = explode("\n", $contents);
        $index = $lineNumber - 1;

        if (!isset($lines[$index])) {
            return null;
        }

        $line = $lines[$index];
        $matched = preg_match('/\S/', $line, $matches, PREG_OFFSET_CAPTURE);
        $column = $matched === 1 && isset($matches[0][1]) && is_int($matches[0][1]) ? $matches[0][1] : 0;

        return new SourceRange($index, $column, $index, $column + 1);
    }
}

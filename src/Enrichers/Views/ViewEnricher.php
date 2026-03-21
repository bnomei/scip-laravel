<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Views;

use Bnomei\ScipLaravel\Application\BladeClassComponentInventoryBuilder;
use Bnomei\ScipLaravel\Application\FluxComponentContractInventoryBuilder;
use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\LivewireComponentContext;
use Bnomei\ScipLaravel\Application\LivewireComponentInventory;
use Bnomei\ScipLaravel\Application\LivewireComponentInventoryBuilder;
use Bnomei\ScipLaravel\Application\PrefixedAnonymousComponentInventoryBuilder;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeLiteralReference;
use Bnomei\ScipLaravel\Blade\BladeLivewireEventReference;
use Bnomei\ScipLaravel\Blade\BladeLocalSymbolDeclaration;
use Bnomei\ScipLaravel\Blade\BladeLocalSymbolScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Blade\BladeUnsupportedSite;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\LivewirePhpEntrypoints;
use Bnomei\ScipLaravel\Support\PhpLiteralCall;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Scip\Diagnostic;
use Scip\DiagnosticTag;
use Scip\Document;
use Scip\Occurrence;
use Scip\Severity;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function is_array;
use function is_dir;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function preg_match;
use function preg_match_all;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;

final class ViewEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly BladeDirectiveScanner $bladeScanner = new BladeDirectiveScanner(),
        private readonly BladeLocalSymbolScanner $bladeLocalScanner = new BladeLocalSymbolScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly LivewireComponentInventoryBuilder $livewireInventoryBuilder = new LivewireComponentInventoryBuilder(),
        private readonly PrefixedAnonymousComponentInventoryBuilder $prefixedInventoryBuilder = new PrefixedAnonymousComponentInventoryBuilder(),
        private readonly BladeClassComponentInventoryBuilder $classComponentInventoryBuilder = new BladeClassComponentInventoryBuilder(),
        private readonly FluxComponentContractInventoryBuilder $fluxContractInventoryBuilder = new FluxComponentContractInventoryBuilder(),
        private readonly FrameworkExternalSymbolFactory $frameworkExternalSymbols = new FrameworkExternalSymbolFactory(),
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    /**
     * @var array<string, ?string>
     */
    private array $resolvedViewPathCache = [];

    public function feature(): string
    {
        return 'views';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $finder = $this->viewFinder($context);

        if ($finder === null) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $definitionPayload = $this->definitionDocuments($context, $finder, $normalizer);

        if ($definitionPayload['documents'] === []) {
            return IndexPatch::empty();
        }

        $livewireInventory = $this->livewireInventoryBuilder->collect($context);
        $prefixedInventory = $this->prefixedInventoryBuilder->collect($context);
        $classComponentInventory = $this->classComponentInventoryBuilder->collect($context);
        $fluxContracts = $this->fluxContractInventoryBuilder->collect($context);
        $references = [];
        $localSymbolPatches = [];
        $localOccurrencePatches = [];
        $externalSymbols = [];

        foreach ($fluxContracts->localDocumentationByViewName as $viewName => $documentation) {
            $symbol = $definitionPayload['symbolsByName'][$viewName] ?? null;
            $resolvedPath = $definitionPayload['pathsByName'][$viewName] ?? null;

            if (!is_string($symbol) || !is_string($resolvedPath) || $documentation === []) {
                continue;
            }

            $localSymbolPatches[] = new DocumentSymbolPatch(
                documentPath: $context->relativeProjectPath($resolvedPath),
                symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'documentation' => $documentation,
                ]),
            );
        }

        foreach ($this->callFinder->find(
            $context->projectRoot,
            ['view'],
            [
                'Illuminate\\Support\\Facades\\View' => ['make'],
                ...LivewirePhpEntrypoints::staticMethods(),
            ],
        ) as $call) {
            $symbol = $this->resolvedPhpViewSymbol(
                $call,
                $finder,
                $definitionPayload['symbolsByName'],
                $definitionPayload['pathsByName'],
                $definitionPayload['symbolsByPath'],
                $livewireInventory,
            );

            if ($symbol === null) {
                if (isset($definitionPayload['ambiguousNames'][$call->literal])) {
                    $references[] = $this->diagnosticOccurrencePatch(
                        $context->relativeProjectPath($call->filePath),
                        $call->range,
                        SyntaxKind::StringLiteralKey,
                        'blade.ambiguous-view-target',
                        'Ambiguous Blade view target.',
                    );
                }

                continue;
            }

            $references[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]),
            );
        }

        foreach ($this->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);
            $componentContext = $livewireInventory->forDocument($documentPath);
            $classComponentContext = $classComponentInventory->forDocument($documentPath);
            $localDeclarations = $this->bladeLocalScanner->scanDeclarations($contents);
            $ownerSymbol = $definitionPayload['symbolsByPath'][$documentPath] ?? null;
            $componentVariableDeclarations = $classComponentContext === null
                ? []
                : $this->bladeVariableDeclarations($contents, $classComponentContext->propertySymbols);
            $attributeVariableDeclarations = [];

            if ($this->isComponentBladeDocument($documentPath)) {
                $attributeBag = $this->frameworkExternalSymbols->componentAttributeBag();
                $externalSymbols[$attributeBag->getSymbol()] = $attributeBag;
                $attributeVariableDeclarations = $this->bladeVariableDeclarations($contents, ['attributes' =>
                    $attributeBag->getSymbol()]);
            }

            [$localVariableReferences, $componentVariableReferences, $attributeVariableReferences] =
                $this->bladeLocalScanner->scanVariableReadsByGroups($contents, [
                    $localDeclarations,
                    $componentVariableDeclarations,
                    $attributeVariableDeclarations,
                ]);

            foreach ($this->skippedBladeDiagnosticOccurrences($documentPath, $contents) as $diagnosticOccurrence) {
                $references[] = $diagnosticOccurrence;
            }

            foreach ($localDeclarations as $declaration) {
                $localSymbolPatches[] =
                    new DocumentSymbolPatch(documentPath: $documentPath, symbol: $this->bladeLocalSymbolInformation(
                        $declaration,
                        is_string($ownerSymbol) ? $ownerSymbol : null,
                    ));
                $localOccurrencePatches[] =
                    new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                        'range' => $declaration->range->toScipRange(),
                        'symbol' => $declaration->symbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => $this->bladeLocalSyntaxKind(),
                        'enclosing_range' => $declaration->enclosingRange->toScipRange(),
                    ]));
            }

            foreach ($localVariableReferences as $reference) {
                $localOccurrencePatches[] =
                    new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                        'range' => $reference->range->toScipRange(),
                        'symbol' => $reference->symbol,
                        'symbol_roles' => SymbolRole::ReadAccess,
                        'syntax_kind' => $this->bladeLocalSyntaxKind(),
                    ]));
            }

            foreach ($this->bladeScanner->scanViewReferences($contents, $prefixedInventory->prefixes) as $reference) {
                $symbol = $this->resolvedBladeViewSymbol(
                    $reference,
                    $finder,
                    $definitionPayload['symbolsByName'],
                    $definitionPayload['pathsByName'],
                    $definitionPayload['symbolsByPath'],
                    $prefixedInventory->resolvedViewNamesByTag,
                    $livewireInventory,
                );

                if ($symbol !== null) {
                    $references[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->range->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => $this->referenceSyntaxKind($reference->directive),
                        ]));
                } elseif (isset($definitionPayload['ambiguousNames'][$reference->literal])) {
                    $references[] = $this->diagnosticOccurrencePatch(
                        $documentPath,
                        $reference->range,
                        SyntaxKind::StringLiteralKey,
                        'blade.ambiguous-view-target',
                        'Ambiguous Blade view target.',
                    );
                }

                if ($reference->directive === 'prefixed-component-tag') {
                    $packageName = $prefixedInventory->externalPackagesByTag[$reference->literal] ?? null;

                    if (is_string($packageName) && $packageName !== '') {
                        $symbol = $this->frameworkExternalSymbols->vendorBladeComponent(
                            $packageName,
                            $reference->literal,
                        );

                        if (isset($fluxContracts->externalDocumentationByTag[$reference->literal])) {
                            $symbol->setDocumentation(array_values(array_unique([
                                ...iterator_to_array($symbol->getDocumentation(), false),
                                ...$fluxContracts->externalDocumentationByTag[$reference->literal],
                            ])));
                        }

                        $externalSymbols[$symbol->getSymbol()] = $symbol;
                        $references[] =
                            new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                                'range' => $reference->range->toScipRange(),
                                'symbol' => $symbol->getSymbol(),
                                'symbol_roles' => SymbolRole::ReadAccess,
                                'syntax_kind' => $this->referenceSyntaxKind($reference->directive),
                            ]));
                    }
                }

                if ($reference->directive === 'blade-component-tag') {
                    $alias = $this->bladeComponentAlias($reference);
                    $classSymbol = $alias === null ? null : $classComponentInventory->forAlias($alias)?->classSymbol;

                    if ($classSymbol !== null) {
                        $references[] =
                            new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                                'range' => $reference->range->toScipRange(),
                                'symbol' => $classSymbol,
                                'symbol_roles' => SymbolRole::ReadAccess,
                                'syntax_kind' => $this->referenceSyntaxKind($reference->directive),
                            ]));
                    }
                }
            }

            foreach ($this->bladeScanner->scanUnsupportedSites($contents) as $site) {
                $references[] = new DocumentOccurrencePatch(
                    documentPath: $documentPath,
                    occurrence: $this->unsupportedSiteOccurrence($site),
                );
            }

            foreach ($componentVariableReferences as $reference) {
                $references[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $reference->symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $this->bladeLocalSyntaxKind(),
                ]));
            }

            foreach ($attributeVariableReferences as $reference) {
                $references[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $reference->symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $this->bladeLocalSyntaxKind(),
                ]));
            }

            if ($componentContext !== null) {
                foreach ($this->livewireChildBindingOccurrences(
                    $documentPath,
                    $contents,
                    $componentContext,
                    $livewireInventory,
                ) as $occurrence) {
                    $references[] = $occurrence;
                }

                foreach ($this->bladeScanner->scanLivewireDirectiveReferences($contents) as $reference) {
                    $symbol = $this->resolvedLivewireSymbol($componentContext, $reference);

                    if ($symbol === null) {
                        continue;
                    }

                    $references[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->range->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => $this->livewireDirectiveRoles($reference->directive),
                            'syntax_kind' => SyntaxKind::Identifier,
                        ]));
                }
            }

            foreach ($this->bladeScanner->scanLivewireEventReferences($contents) as $reference) {
                $eventSymbol = $this->frameworkExternalSymbols->livewireEvent($reference->eventName);
                $externalSymbols[$eventSymbol->getSymbol()] = $eventSymbol;
                $references[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->eventRange->toScipRange(),
                    'symbol' => $eventSymbol->getSymbol(),
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $this->livewireEventSyntaxKind($reference),
                ]));

                if ($componentContext === null || $reference->methodName === null || $reference->methodRange === null) {
                    continue;
                }

                $methodSymbol = $componentContext->methodSymbols[$reference->methodName] ?? null;

                if ($methodSymbol === null) {
                    continue;
                }

                $references[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->methodRange->toScipRange(),
                    'symbol' => $methodSymbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));
            }
        }

        return new IndexPatch(
            documents: $definitionPayload['documents'],
            symbols: [
                ...$livewireInventory->symbolPatches(),
                ...$localSymbolPatches,
            ],
            externalSymbols: array_values($externalSymbols),
            occurrences: [
                ...$livewireInventory->definitionPatches(),
                ...$localOccurrencePatches,
                ...$references,
            ],
        );
    }

    /**
     * @return array{
     *     documents: list<Document>,
     *     symbolsByName: array<string, string>,
     *     pathsByName: array<string, string>,
     *     symbolsByPath: array<string, string>,
     *     ambiguousNames: array<string, true>
     * }
     */
    private function definitionDocuments(
        LaravelContext $context,
        object $finder,
        SyntheticSymbolNormalizer $normalizer,
    ): array {
        $candidatePathsByName = [];

        foreach ($this->viewRoots($context, $finder) as $root) {
            foreach ($this->viewFiles($root['path']) as $filePath) {
                $name = $this->viewName($root['path'], $filePath, $root['namespace']);
                $candidatePathsByName[$name][$filePath] = true;
            }
        }

        if ($candidatePathsByName === []) {
            return [
                'documents' => [],
                'symbolsByName' => [],
                'pathsByName' => [],
                'symbolsByPath' => [],
                'ambiguousNames' => [],
            ];
        }

        ksort($candidatePathsByName);
        $resolvedPathsByName = [];
        $contentsByPath = [];
        $ambiguousNames = [];

        foreach ($candidatePathsByName as $name => $paths) {
            if (count($paths) !== 1) {
                $ambiguousNames[$name] = true;
                continue;
            }

            $candidatePath = array_keys($paths)[0];
            $resolvedPath = $this->resolveViewPath($finder, $name);

            if ($resolvedPath === null || $resolvedPath !== $candidatePath) {
                continue;
            }

            $contents = $this->bladeCache->contents($resolvedPath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $resolvedPathsByName[$name] = $resolvedPath;
            $contentsByPath[$resolvedPath] = $contents;
        }

        if ($resolvedPathsByName === []) {
            return [
                'documents' => [],
                'symbolsByName' => [],
                'pathsByName' => [],
                'symbolsByPath' => [],
                'ambiguousNames' => $ambiguousNames,
            ];
        }

        $namesByResolvedPath = [];

        foreach ($resolvedPathsByName as $name => $resolvedPath) {
            $namesByResolvedPath[$resolvedPath][$name] = true;
        }

        ksort($namesByResolvedPath);
        $documentsByPath = [];
        $symbolsByName = [];
        $pathsByName = [];
        $symbolsByPath = [];

        foreach ($namesByResolvedPath as $resolvedPath => $names) {
            $contents = $contentsByPath[$resolvedPath] ?? null;

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $canonicalName = $this->canonicalViewName(array_keys($names));
            $range = $this->definitionRange($contents);

            if ($range === null) {
                continue;
            }

            $symbol = $normalizer->view($canonicalName);
            $relativePath = $context->relativeProjectPath($resolvedPath);

            foreach (array_keys($names) as $name) {
                $symbolsByName[$name] = $symbol;
                $pathsByName[$name] = $resolvedPath;
            }

            $symbolsByPath[$relativePath] = $symbol;

            if (!isset($documentsByPath[$relativePath])) {
                $documentsByPath[$relativePath] = [
                    'language' => $this->viewLanguage($resolvedPath),
                    'relative_path' => $relativePath,
                    'symbols' => [],
                    'occurrences' => [],
                    'text' => $contents,
                ];
            }

            $documentsByPath[$relativePath]['symbols'][$symbol] = new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $canonicalName,
                'kind' => Kind::File,
            ]);
            $documentsByPath[$relativePath]['occurrences'][$symbol] = new Occurrence([
                'range' => $range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::Identifier,
            ]);
        }

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

        return [
            'documents' => $documents,
            'symbolsByName' => $symbolsByName,
            'pathsByName' => $pathsByName,
            'symbolsByPath' => $symbolsByPath,
            'ambiguousNames' => $ambiguousNames,
        ];
    }

    /**
     * @param list<string> $names
     */
    private function canonicalViewName(array $names): string
    {
        sort($names);
        $canonical = $names[0];
        $canonicalPriority = $this->viewAliasPriority($canonical);

        foreach ($names as $name) {
            $priority = $this->viewAliasPriority($name);

            if ($priority < $canonicalPriority || $priority === $canonicalPriority && strcmp($name, $canonical) < 0) {
                $canonical = $name;
                $canonicalPriority = $priority;
            }
        }

        return $canonical;
    }

    private function viewAliasPriority(string $name): int
    {
        return str_contains($name, '::') ? 1 : 0;
    }

    private function viewFinder(LaravelContext $context): ?object
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $factory = $context->application->make('view');
        } catch (Throwable) {
            return null;
        }

        if (!is_object($factory) || !method_exists($factory, 'getFinder')) {
            return null;
        }

        $finder = $factory->getFinder();

        return is_object($finder) ? $finder : null;
    }

    /**
     * @return list<array{namespace: ?string, path: string}>
     */
    private function viewRoots(LaravelContext $context, object $finder): array
    {
        $roots = [];

        if (method_exists($finder, 'getPaths')) {
            foreach ($finder->getPaths() as $path) {
                if (is_string($path) && $this->isLocalProjectPath($context, $path) && is_dir($path)) {
                    $roots[] = ['namespace' => null, 'path' => $path];
                }
            }
        }

        if (method_exists($finder, 'getHints')) {
            foreach ($finder->getHints() as $namespace => $paths) {
                if (!is_string($namespace) || !is_array($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    if (is_string($path) && $this->isLocalProjectPath($context, $path) && is_dir($path)) {
                        $roots[] = ['namespace' => $namespace, 'path' => $path];
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function viewFiles(string $root): array
    {
        return $this->bladeCache->viewFiles($root);
    }

    private function viewName(string $root, string $filePath, ?string $namespace): string
    {
        $relativePath = substr($filePath, strlen($root) + 1);
        $name = str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);

        foreach (['.blade.php', '.php', '.html'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        return $namespace === null ? $name : $namespace . '::' . $name;
    }

    private function resolveViewPath(object $finder, string $name): ?string
    {
        $cacheKey = spl_object_id($finder) . ':' . $name;

        if (array_key_exists($cacheKey, $this->resolvedViewPathCache)) {
            return $this->resolvedViewPathCache[$cacheKey];
        }

        if (!method_exists($finder, 'find')) {
            return $this->resolvedViewPathCache[$cacheKey] = null;
        }

        try {
            $path = $finder->find($name);
        } catch (Throwable) {
            return $this->resolvedViewPathCache[$cacheKey] = null;
        }

        return $this->resolvedViewPathCache[$cacheKey] = is_string($path) && $path !== ''
            ? (realpath($path) ?: $path)
            : null;
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

    private function viewLanguage(string $path): string
    {
        return str_ends_with($path, '.blade.php') ? 'blade' : (str_ends_with($path, '.php') ? 'php' : 'html');
    }

    private function phpViewName(PhpLiteralCall $call): ?string
    {
        if ($call->callee === 'view' || $call->callee === 'illuminate\\support\\facades\\view::make') {
            return $call->literal;
        }

        return LivewirePhpEntrypoints::viewName($call);
    }

    /**
     * @param array<string, string> $symbolsByName
     * @param array<string, string> $pathsByName
     * @param array<string, string> $symbolsByPath
     */
    private function resolvedPhpViewSymbol(
        PhpLiteralCall $call,
        object $finder,
        array $symbolsByName,
        array $pathsByName,
        array $symbolsByPath,
        LivewireComponentInventory $livewireInventory,
    ): ?string {
        $viewName = $this->phpViewName($call);
        $symbol = $viewName === null
            ? null
            : $this->resolvedViewSymbolByName($finder, $viewName, $symbolsByName, $pathsByName);

        if ($symbol !== null) {
            return $symbol;
        }

        $alias = $this->livewireAliasFromLiteral($call->literal);

        if ($alias === null) {
            return null;
        }

        $documentPath = $livewireInventory->forAlias($alias)?->documentPath;

        return is_string($documentPath) ? $symbolsByPath[$documentPath] ?? null : null;
    }

    private function referenceSyntaxKind(string $directive): int
    {
        return $directive === 'livewire-tag'
        || $directive === 'blade-component-tag'
        || $directive === 'prefixed-component-tag'
            ? SyntaxKind::Identifier
            : SyntaxKind::StringLiteralKey;
    }

    private function livewireDirectiveRoles(string $directive): int
    {
        return $directive === 'wire-model' ? SymbolRole::ReadAccess | SymbolRole::WriteAccess : SymbolRole::ReadAccess;
    }

    /**
     * @return list<DocumentOccurrencePatch>
     */
    private function livewireChildBindingOccurrences(
        string $documentPath,
        string $contents,
        LivewireComponentContext $parentContext,
        LivewireComponentInventory $inventory,
    ): array {
        $occurrences = [];

        foreach ($this->bladeScanner->scanLivewireChildBindingReferences($contents) as $reference) {
            $childContext = $inventory->forAlias($reference->childAlias);

            if (!$childContext instanceof LivewireComponentContext) {
                continue;
            }

            $parentSymbol = $parentContext->propertySymbols[$reference->parentProperty] ?? null;

            if ($parentSymbol === null) {
                continue;
            }

            if ($reference->kind === 'modelable') {
                if (count($childContext->modelableProperties) !== 1) {
                    continue;
                }

                $childProperty = $childContext->modelableProperties[0];
                $childSymbol = $childContext->propertySymbols[$childProperty] ?? null;

                if ($childSymbol === null) {
                    continue;
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->parentRange->toScipRange(),
                    'symbol' => $parentSymbol,
                    'symbol_roles' => SymbolRole::ReadAccess | SymbolRole::WriteAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->parentRange->toScipRange(),
                    'symbol' => $childSymbol,
                    'symbol_roles' => SymbolRole::ReadAccess | SymbolRole::WriteAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));

                continue;
            }

            if ($reference->childProperty === null || $reference->childRange === null) {
                continue;
            }

            if (!in_array($reference->childProperty, $childContext->reactiveProperties, true)) {
                continue;
            }

            $childSymbol = $childContext->propertySymbols[$reference->childProperty] ?? null;

            if ($childSymbol === null) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->parentRange->toScipRange(),
                'symbol' => $parentSymbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::Identifier,
            ]));
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->childRange->toScipRange(),
                'symbol' => $childSymbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::TagAttribute,
            ]));
        }

        return $occurrences;
    }

    private function livewireEventSyntaxKind(BladeLivewireEventReference $reference): int
    {
        return $reference->kind === 'dispatch' ? SyntaxKind::StringLiteralKey : SyntaxKind::Identifier;
    }

    private function bladeLocalSymbolInformation(
        BladeLocalSymbolDeclaration $declaration,
        ?string $enclosingSymbol = null,
    ): SymbolInformation {
        $payload = [
            'symbol' => $declaration->symbol,
            'display_name' => $declaration->name,
            'kind' => match ($declaration->kind) {
                'prop', 'aware' => Kind::Parameter,
                'slot' => Kind::Key,
                default => Kind::Variable,
            },
        ];

        if ($enclosingSymbol !== null && $enclosingSymbol !== '') {
            $payload['enclosing_symbol'] = $enclosingSymbol;
        }

        return new SymbolInformation($payload);
    }

    private function bladeLocalSyntaxKind(): int
    {
        return SyntaxKind::IdentifierLocal;
    }

    private function resolvedLivewireSymbol(
        LivewireComponentContext $componentContext,
        BladeLiteralReference $reference,
    ): ?string {
        return match ($reference->directive) {
            'wire-model' => $componentContext->propertySymbols[$reference->literal] ?? null,
            'wire-submit', 'wire-click' => $componentContext->methodSymbols[$reference->literal] ?? null,
            default => null,
        };
    }

    /**
     * @param array<string, string> $resolvedViewNamesByTag
     */
    private function resolvedBladeViewName(BladeLiteralReference $reference, array $resolvedViewNamesByTag): ?string
    {
        if ($reference->directive === 'prefixed-component-tag') {
            return $resolvedViewNamesByTag[$reference->literal] ?? null;
        }

        return $reference->literal;
    }

    /**
     * @param array<string, string> $symbolsByName
     * @param array<string, string> $pathsByName
     * @param array<string, string> $symbolsByPath
     * @param array<string, string> $resolvedViewNamesByTag
     */
    private function resolvedBladeViewSymbol(
        BladeLiteralReference $reference,
        object $finder,
        array $symbolsByName,
        array $pathsByName,
        array $symbolsByPath,
        array $resolvedViewNamesByTag,
        LivewireComponentInventory $livewireInventory,
    ): ?string {
        $viewName = $this->resolvedBladeViewName($reference, $resolvedViewNamesByTag);
        $symbol = $viewName === null
            ? null
            : $this->resolvedViewSymbolByName($finder, $viewName, $symbolsByName, $pathsByName);

        if ($symbol !== null) {
            return $symbol;
        }

        $alias = $this->livewireAliasFromBladeReference($reference);

        if ($alias === null) {
            return null;
        }

        $documentPath = $livewireInventory->forAlias($alias)?->documentPath;

        return is_string($documentPath) ? $symbolsByPath[$documentPath] ?? null : null;
    }

    /**
     * @param array<string, string> $symbolsByName
     * @param array<string, string> $pathsByName
     */
    private function resolvedViewSymbolByName(
        object $finder,
        string $viewName,
        array $symbolsByName,
        array $pathsByName,
    ): ?string {
        if (!array_key_exists($viewName, $pathsByName)) {
            return null;
        }

        $resolvedPath = $this->resolveViewPath($finder, $viewName);

        if ($resolvedPath === null || $resolvedPath !== $pathsByName[$viewName]) {
            return null;
        }

        return $symbolsByName[$viewName] ?? null;
    }

    private function livewireAliasFromBladeReference(BladeLiteralReference $reference): ?string
    {
        if (!str_starts_with($reference->literal, 'livewire.')) {
            return null;
        }

        return $this->livewireAliasFromLiteral(substr($reference->literal, strlen('livewire.')));
    }

    private function livewireAliasFromLiteral(string $literal): ?string
    {
        $literal = str_replace(['::', '/', '\\'], ['.', '.', '.'], $literal);
        $literal = str_starts_with($literal, 'livewire.') ? substr($literal, strlen('livewire.')) : $literal;

        return $literal !== '' ? $literal : null;
    }

    private function bladeComponentAlias(BladeLiteralReference $reference): ?string
    {
        return str_starts_with($reference->literal, 'components.')
            ? substr($reference->literal, strlen('components.'))
            : null;
    }

    private function unsupportedSiteOccurrence(BladeUnsupportedSite $site): Occurrence
    {
        return new Occurrence([
            'range' => $site->range->toScipRange(),
            'syntax_kind' => $site->syntaxKind,
            'diagnostics' => [
                new Diagnostic([
                    'severity' => Severity::Information,
                    'code' => $site->code,
                    'message' => $site->message,
                    'source' => 'scip-laravel',
                    'tags' => [DiagnosticTag::UnspecifiedDiagnosticTag],
                ]),
            ],
        ]);
    }

    private function diagnosticOccurrencePatch(
        string $documentPath,
        SourceRange $range,
        int $syntaxKind,
        string $code,
        string $message,
    ): DocumentOccurrencePatch {
        return new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
            'range' => $range->toScipRange(),
            'syntax_kind' => $syntaxKind,
            'diagnostics' => [
                new Diagnostic([
                    'severity' => Severity::Information,
                    'code' => $code,
                    'message' => $message,
                    'source' => 'scip-laravel',
                    'tags' => [DiagnosticTag::UnspecifiedDiagnosticTag],
                ]),
            ],
        ]));
    }

    /**
     * @param array<string, string> $symbolsByName
     * @return list<BladeLocalSymbolDeclaration>
     */
    private function bladeVariableDeclarations(string $contents, array $symbolsByName): array
    {
        if ($symbolsByName === []) {
            return [];
        }

        $zeroRange = SourceRange::fromOffsets($contents, 0, 0);
        $declarations = [];

        foreach ($symbolsByName as $name => $symbol) {
            $declarations[] = new BladeLocalSymbolDeclaration(
                kind: 'component',
                name: $name,
                symbol: $symbol,
                range: $zeroRange,
                enclosingRange: $zeroRange,
            );
        }

        return $declarations;
    }

    private function isComponentBladeDocument(string $documentPath): bool
    {
        return str_starts_with($documentPath, 'resources/views/components/');
    }

    private function isProjectPath(LaravelContext $context, string $path): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedRoot = realpath($context->projectRoot) ?: $context->projectRoot;
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, $rootPrefix);
    }

    private function isLocalProjectPath(LaravelContext $context, string $path): bool
    {
        return (
            $this->isProjectPath($context, $path)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
        );
    }

    /**
     * @return list<DocumentOccurrencePatch>
     */
    private function skippedBladeDiagnosticOccurrences(string $documentPath, string $contents): array
    {
        $occurrences = [];

        foreach ($this->dynamicViewDirectiveDiagnostics($contents) as $diagnostic) {
            $occurrences[] = $this->diagnosticOccurrencePatch(
                $documentPath,
                $diagnostic['range'],
                SyntaxKind::Identifier,
                $diagnostic['code'],
                $diagnostic['message'],
            );
        }

        return $occurrences;
    }

    /**
     * @return list<array{range: SourceRange, code: string, message: string}>
     */
    private function dynamicViewDirectiveDiagnostics(string $contents): array
    {
        $matches = [];
        $count = preg_match_all(
            '/@(?<directive>includeUnless|includeWhen|includeIf|include|extends|livewire)\s*\(\s*(?![\'"])/',
            $contents,
            $found,
            PREG_OFFSET_CAPTURE,
        );

        if ($count === false) {
            return [];
        }

        foreach ($found['directive'] ?? [] as [$directive, $offset]) {
            if (!is_string($directive) || !is_int($offset)) {
                continue;
            }

            $matches[$offset] = [
                'range' => SourceRange::fromOffsets($contents, $offset, $offset + strlen($directive)),
                'code' => 'blade.dynamic-view-target',
                'message' => 'Unsupported dynamic Blade view target.',
            ];
        }

        ksort($matches);

        return array_values($matches);
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Views;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeLayoutContractReference;
use Bnomei\ScipLaravel\Blade\BladeLivewireNavigationReference;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\PhpRouteDeclarationFinder;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Laravel\Ranger\Components\Route as RangerRoute;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_count_values;
use function array_filter;
use function array_values;
use function count;
use function is_array;
use function is_dir;
use function is_object;
use function is_string;
use function ksort;
use function method_exists;
use function realpath;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

final class BladeContractsEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly BladeDirectiveScanner $scanner = new BladeDirectiveScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly PhpRouteDeclarationFinder $routeDeclarationFinder = new PhpRouteDeclarationFinder(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

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

        $catalog = $this->viewCatalog($context, $finder);

        if ($catalog['namesByDocumentPath'] === []) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbols = [];
        $occurrences = [];
        $documentationBySymbol = [];
        $definedSyntheticSymbols = [];
        $parentViewByDocumentPath = $this->parentViewByDocumentPath($context, $catalog);
        $routeSymbolsByName = [];
        $routeSymbolsByPath = [];
        $routeDefinitionPathByName = $this->routeDefinitionPathByName($context);

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!$route instanceof RangerRoute) {
                continue;
            }

            $name = $route->name();
            $uri = $route->uri();

            if (!is_string($name) || $name === '') {
                continue;
            }

            $routeSymbolsByName[$name] = $normalizer->route($name);

            if (!is_string($uri) || $uri === '' || str_contains($uri, '{')) {
                continue;
            }

            $path = '/' . ltrim($uri, '/');

            if (isset($routeSymbolsByPath[$path])) {
                $routeSymbolsByPath[$path] = null;
                continue;
            }

            $routeSymbolsByPath[$path] = $normalizer->route($name);
        }

        foreach ($catalog['namesByDocumentPath'] as $documentPath => $viewName) {
            $filePath =
                $context->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documentPath);
            $resolvedFilePath = realpath($filePath) ?: $filePath;
            $contents = $this->bladeCache->contents($resolvedFilePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            foreach ($this->scanner->scanLivewireNavigationReferences($contents) as $reference) {
                $symbol = $reference->targetKind === 'route-name'
                    ? $routeSymbolsByName[$reference->target] ?? null
                    : $routeSymbolsByPath[$reference->target] ?? null;

                if ($symbol === null) {
                    continue;
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->targetRange->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));

                if ($reference->currentRange !== null) {
                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->currentRange->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                        ]));
                }

                $definitionPath = $this->routeDefinitionDocumentPath(
                    $reference,
                    $context,
                    $routeDefinitionPathByName,
                    $routeSymbolsByName,
                    $routeSymbolsByPath,
                );

                if ($definitionPath === null) {
                    continue;
                }

                if ($reference->navigateModifiers !== []) {
                    $documentationBySymbol[$definitionPath][$symbol][] =
                        'Livewire navigate modifiers: ' . implode(', ', $reference->navigateModifiers);
                } else {
                    $documentationBySymbol[$definitionPath][$symbol][] = 'Livewire navigate';
                }

                if ($reference->currentMode !== null && $reference->currentMode !== '') {
                    $documentationBySymbol[$definitionPath][$symbol][] =
                        'Livewire current state: ' . $reference->currentMode;
                }
            }

            foreach ($this->scanner->scanLayoutContractReferences($contents) as $reference) {
                $ownerView = $reference->kind === 'consume'
                    ? $viewName
                    : $parentViewByDocumentPath[$documentPath] ?? null;

                if ($ownerView === null) {
                    continue;
                }

                $symbol = $normalizer->domainSymbol(
                    'blade-' . $reference->family,
                    $ownerView . '::' . $reference->name,
                );

                if ($reference->kind === 'consume') {
                    $this->defineSyntheticSymbol(
                        documentPath: $documentPath,
                        symbol: $symbol,
                        displayName: $reference->name,
                        documentation: ['Blade ' . $reference->family . ' contract: ' . $reference->name],
                        range: $reference->range->toScipRange(),
                        symbols: $symbols,
                        occurrences: $occurrences,
                        definedSyntheticSymbols: $definedSyntheticSymbols,
                    );
                } else {
                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->range->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                        ]));
                }
            }
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

        return $symbols === [] && $occurrences === []
            ? IndexPatch::empty()
            : new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    private function routeDefinitionDocumentPath(
        BladeLivewireNavigationReference $reference,
        LaravelContext $context,
        array $routeDefinitionPathByName,
        array $routeSymbolsByName,
        array $routeSymbolsByPath,
    ): ?string {
        if ($reference->targetKind === 'route-name') {
            return $routeDefinitionPathByName[$reference->target] ?? null;
        }

        $symbol = $routeSymbolsByPath[$reference->target] ?? null;

        if (!is_string($symbol) || $symbol === '') {
            return null;
        }

        foreach ($routeSymbolsByName as $name => $candidate) {
            if ($candidate === $symbol) {
                return $routeDefinitionPathByName[$name] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   namesByDocumentPath: array<string, string>,
     *   pathsByName: array<string, string>
     * }
     */
    private function viewCatalog(LaravelContext $context, object $finder): array
    {
        $candidatePathsByName = [];

        foreach ($this->viewRoots($context, $finder) as $root) {
            foreach ($this->viewFiles($root['path']) as $filePath) {
                $candidatePathsByName[$this->viewName($root['path'], $filePath, $root['namespace'])][$filePath] = true;
            }
        }

        $pathsByName = [];
        $namesByDocumentPath = [];

        foreach ($candidatePathsByName as $name => $paths) {
            if (count($paths) !== 1) {
                continue;
            }

            $path = array_values(array_keys($paths))[0];
            $resolved = realpath($path) ?: $path;
            $relative = $context->relativeProjectPath($resolved);
            $pathsByName[$name] = $resolved;
            $namesByDocumentPath[$relative] = $name;
        }

        ksort($pathsByName);
        ksort($namesByDocumentPath);

        return [
            'namesByDocumentPath' => $namesByDocumentPath,
            'pathsByName' => $pathsByName,
        ];
    }

    /**
     * @param array{
     *   namesByDocumentPath: array<string, string>,
     *   pathsByName: array<string, string>
     * } $catalog
     * @return array<string, string>
     */
    private function parentViewByDocumentPath(LaravelContext $context, array $catalog): array
    {
        $parents = [];

        foreach ($catalog['namesByDocumentPath'] as $documentPath => $_viewName) {
            $filePath = realpath(
                $context->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documentPath),
            )
            ?: $context->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documentPath);
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $extends = array_values(array_filter(
                $this->scanner->scanViewReferences($contents),
                static fn($reference): bool => $reference->directive === 'extends',
            ));

            if (count($extends) !== 1) {
                continue;
            }

            $viewName = $extends[0]->literal;

            if (isset($catalog['pathsByName'][$viewName])) {
                $parents[$documentPath] = $viewName;
            }
        }

        ksort($parents);

        return $parents;
    }

    /**
     * @return array<string, string>
     */
    private function routeDefinitionPathByName(LaravelContext $context): array
    {
        $definitions = [];
        $counts = [];

        foreach ($this->routeDeclarationFinder->find($context->projectRoot) as $declaration) {
            if ($declaration->nameLiteral === null || $declaration->nameLiteral === '') {
                continue;
            }

            $counts[$declaration->nameLiteral] = ($counts[$declaration->nameLiteral] ?? 0) + 1;
            $definitions[$declaration->nameLiteral] = $context->relativeProjectPath($declaration->filePath);
        }

        foreach ($counts as $name => $count) {
            if ($count !== 1) {
                unset($definitions[$name]);
            }
        }

        ksort($definitions);

        return $definitions;
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
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Routes;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\PhpRouteDeclarationFinder;
use Bnomei\ScipLaravel\Support\ProjectFallbackSymbolResolver;
use Bnomei\ScipLaravel\Support\RouteDepthExtractor;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Laravel\Ranger\Components\Route as RangerRoute;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_unique;
use function array_values;
use function count;
use function enum_exists;
use function is_string;
use function ksort;
use function method_exists;
use function sort;
use function str_contains;

final class RouteDepthEnricher implements Enricher
{
    public function __construct(
        private readonly RouteDepthExtractor $extractor = new RouteDepthExtractor(),
        private readonly PhpRouteDeclarationFinder $routeDeclarationFinder = new PhpRouteDeclarationFinder(),
        private readonly ProjectFallbackSymbolResolver $fallbackResolver = new ProjectFallbackSymbolResolver(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
    ) {}

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $routeSymbolsByName = [];
        $routeSymbolsByPath = [];
        $routeUrisByName = [];
        $definitionPathByName = [];
        $explicitBindings = [];
        $symbols = [];
        $occurrences = [];
        $documentationBySymbol = [];

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
            $routeUrisByName[$name] = $uri;

            if (is_string($uri) && $uri !== '' && !str_contains($uri, '{')) {
                $path = '/' . ltrim($uri, '/');

                if (isset($routeSymbolsByPath[$path])) {
                    $routeSymbolsByPath[$path] = null;
                } else {
                    $routeSymbolsByPath[$path] = $normalizer->route($name);
                }
            }
        }

        $routeNameCounts = [];

        foreach ($this->routeDeclarationFinder->find($context->projectRoot) as $declaration) {
            if ($declaration->nameLiteral === null || $declaration->nameLiteral === '') {
                continue;
            }

            $routeNameCounts[$declaration->nameLiteral] = ($routeNameCounts[$declaration->nameLiteral] ?? 0) + 1;
            $definitionPathByName[$declaration->nameLiteral] = $context->relativeProjectPath($declaration->filePath);
        }

        foreach ($routeNameCounts as $name => $count) {
            if ($count !== 1) {
                unset($definitionPathByName[$name]);
            }
        }

        foreach ($this->extractor->explicitBindings($context->projectRoot) as $binding) {
            $explicitBindings[$binding->parameter] = $binding->className;
        }

        ksort($explicitBindings);

        foreach ($routeSymbolsByName as $name => $routeSymbol) {
            $routeDocumentPath = $definitionPathByName[$name] ?? null;
            $uri = $routeUrisByName[$name] ?? null;

            if ($routeDocumentPath === null) {
                continue;
            }

            foreach ($this->explicitBindingDocumentation($uri, $explicitBindings) as $line) {
                $documentationBySymbol[$routeDocumentPath][$routeSymbol][] = $line;
            }
        }

        foreach ($this->extractor->routeMetadata($context->projectRoot) as $reference) {
            $routeSymbol = $routeSymbolsByName[$reference->routeName] ?? null;
            $routeDocumentPath = $definitionPathByName[$reference->routeName] ?? null;

            if ($routeSymbol === null || $routeDocumentPath === null) {
                continue;
            }

            if ($reference->scopeBindingsState === 'enabled') {
                $documentationBySymbol[$routeDocumentPath][$routeSymbol][] = 'Laravel scoped bindings: enabled';
            } elseif ($reference->scopeBindingsState === 'disabled') {
                $documentationBySymbol[$routeDocumentPath][$routeSymbol][] = 'Laravel scoped bindings: disabled';
            }

            if (
                $reference->missingTarget !== null
                && $reference->missingTargetKind !== null
                && $reference->missingTargetRange !== null
            ) {
                $targetSymbol = $reference->missingTargetKind === 'route-name'
                    ? $routeSymbolsByName[$reference->missingTarget] ?? null
                    : $routeSymbolsByPath[$reference->missingTarget] ?? null;

                if ($targetSymbol !== null) {
                    $occurrences[] = new DocumentOccurrencePatch(
                        documentPath: $context->relativeProjectPath($reference->filePath),
                        occurrence: new Occurrence([
                            'range' => $reference->missingTargetRange->toScipRange(),
                            'symbol' => $targetSymbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                        ]),
                    );
                    $documentationBySymbol[$routeDocumentPath][$routeSymbol][] =
                        'Laravel missing handler: ' . $reference->missingTarget;
                }
            }

            $authorizationClass = $reference->authorizationTargetClassName;

            if ($authorizationClass === null && $reference->authorizationTargetLiteral !== null) {
                $authorizationClass = $explicitBindings[$reference->authorizationTargetLiteral] ?? null;
            }

            if ($authorizationClass !== null && $reference->authorizationTargetRange !== null) {
                $resolved = $this->fallbackResolver->resolveClass($context, $normalizer, $authorizationClass);

                if ($resolved !== null) {
                    if ($resolved->symbolPatch !== null) {
                        $symbols[] = $resolved->symbolPatch;
                    }

                    if ($resolved->definitionPatch !== null) {
                        $occurrences[] = $resolved->definitionPatch;
                    }

                    $occurrences[] = new DocumentOccurrencePatch(
                        documentPath: $context->relativeProjectPath($reference->filePath),
                        occurrence: new Occurrence([
                            'range' => $reference->authorizationTargetRange->toScipRange(),
                            'symbol' => $resolved->symbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                        ]),
                    );
                    $documentationBySymbol[$routeDocumentPath][$routeSymbol][] =
                        'Laravel authorization target: '
                        . $authorizationClass
                        . (
                            $reference->authorizationTargetLiteral !== null
                                ? ' via ' . $reference->authorizationTargetLiteral
                                : ''
                        );
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

    /**
     * @return list<string>
     */
    private function explicitBindingDocumentation(?string $uri, array $explicitBindings): array
    {
        if (!is_string($uri) || $uri === '') {
            return [];
        }

        $documentation = [];

        foreach ($explicitBindings as $parameter => $className) {
            if (!str_contains($uri, '{' . $parameter . '}') && !str_contains($uri, '{' . $parameter . '?}')) {
                continue;
            }

            $documentation[] =
                (enum_exists($className) ? 'Laravel enum binding: ' : 'Laravel explicit binding: ')
                . $parameter
                . ' => '
                . $className;
        }

        return $documentation;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Authorization;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\PhpAuthorizationReferenceFinder;
use Bnomei\ScipLaravel\Support\PhpRouteGuardReferenceFinder;
use Bnomei\ScipLaravel\Support\RouteGuardReference;
use Bnomei\ScipLaravel\Symbols\FrameworkExternalSymbolFactory;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use ReflectionClass;
use ReflectionException;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_values;
use function is_string;
use function ksort;
use function ltrim;
use function sort;

final class MiddlewareAuthorizationEnricher implements Enricher
{
    public function __construct(
        private readonly PhpRouteGuardReferenceFinder $routeReferenceFinder = new PhpRouteGuardReferenceFinder(),
        private readonly PhpAuthorizationReferenceFinder $authorizationReferenceFinder = new PhpAuthorizationReferenceFinder(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly FrameworkExternalSymbolFactory $externalSymbols = new FrameworkExternalSymbolFactory(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
    ) {}

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbols = [];
        $occurrences = [];
        $externalSymbols = [];
        $documentationByDocument = [];

        foreach ($this->routeReferenceFinder->find($context->projectRoot) as $reference) {
            $documentPath = $context->relativeProjectPath($reference->filePath);
            $symbol = null;

            if ($reference->kind === 'middleware-class') {
                $symbol = $this->middlewareClassSymbol($context, $reference->literal);
            } elseif ($reference->kind === 'middleware-alias') {
                $external = $this->externalSymbols->middlewareAlias($reference->literal);
                $externalSymbols[$external->getSymbol()] = $external;
                $symbol = $external->getSymbol();
            } elseif ($reference->kind === 'ability') {
                $external = $this->externalSymbols->authorizationAbility($reference->literal);
                $externalSymbols[$external->getSymbol()] = $external;
                $symbol = $external->getSymbol();
            }

            if ($symbol === null) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => $reference->kind === 'middleware-class'
                    ? SyntaxKind::Identifier
                    : SyntaxKind::StringLiteralKey,
            ]));

            $line = $this->routeDocumentationLine($reference);

            if ($line !== null) {
                $documentationByDocument[$documentPath][$normalizer->route($reference->routeName)][] = $line;
            }
        }

        foreach ($this->authorizationReferenceFinder->find($context->projectRoot) as $reference) {
            $documentPath = $context->relativeProjectPath($reference->filePath);
            $external = $this->externalSymbols->authorizationAbility($reference->ability);
            $externalSymbols[$external->getSymbol()] = $external;
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $reference->range->toScipRange(),
                'symbol' => $external->getSymbol(),
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));

            $methodSymbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $reference->methodName,
                $reference->methodLine,
            );

            if ($methodSymbol === null) {
                continue;
            }

            $documentationByDocument[$documentPath][$methodSymbol][] =
                'Laravel authorization ability: ' . $reference->ability;
        }

        ksort($documentationByDocument);

        foreach ($documentationByDocument as $documentPath => $bySymbol) {
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

    private function middlewareClassSymbol(LaravelContext $context, string $className): ?string
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        try {
            $lineNumber = $reflection->getStartLine();
        } catch (Throwable) {
            return null;
        }

        return $this->classSymbolResolver->resolve(
            $context->baselineIndex,
            $context->relativeProjectPath($filePath),
            ltrim($className, '\\'),
            $lineNumber,
        );
    }

    private function routeDocumentationLine(RouteGuardReference $reference): ?string
    {
        if ($reference->kind === 'ability') {
            return 'Laravel authorization ability: ' . $reference->literal;
        }

        $prefix = $reference->mode === 'excluded' ? 'Laravel middleware excluded: ' : 'Laravel middleware: ';

        return $prefix . $reference->literal;
    }
}

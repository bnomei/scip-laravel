<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Authorization;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\PolicyClassResolver;
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
use Throwable;

use function array_unique;
use function array_values;
use function is_string;
use function ksort;

final class BladeAuthorizationEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly BladeDirectiveScanner $scanner = new BladeDirectiveScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly FrameworkExternalSymbolFactory $externalSymbols = new FrameworkExternalSymbolFactory(),
        private readonly PolicyClassResolver $policyResolver = new PolicyClassResolver(),
        private readonly ProjectFallbackSymbolResolver $fallbackSymbolResolver = new ProjectFallbackSymbolResolver(),
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
        $occurrences = [];
        $symbols = [];
        $externalSymbols = [];
        $documentationBySymbol = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));

        foreach ($this->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);

            foreach ($this->scanner->scanAuthorizationReferences($contents) as $reference) {
                $abilitySymbol = $this->externalSymbols->authorizationAbility($reference->ability);
                $externalSymbols[$abilitySymbol->getSymbol()] = $abilitySymbol;
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->abilityRange->toScipRange(),
                    'symbol' => $abilitySymbol->getSymbol(),
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));

                if ($reference->targetClassName === null || $reference->targetClassRange === null) {
                    continue;
                }

                $targetPayload = $this->classSymbolPayload($context, $normalizer, $reference->targetClassName);

                if ($targetPayload !== null) {
                    if ($targetPayload['symbolPatch'] !== null) {
                        $symbols[] = $targetPayload['symbolPatch'];
                    }

                    if ($targetPayload['definitionPatch'] !== null) {
                        $occurrences[] = $targetPayload['definitionPatch'];
                    }

                    if ($targetPayload['external'] !== null) {
                        $externalSymbols[$targetPayload['external']->getSymbol()] = $targetPayload['external'];
                    }

                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => $reference->targetClassRange->toScipRange(),
                            'symbol' => $targetPayload['symbol'],
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::Identifier,
                        ]));
                }

                $policyMethodPayload = $this->policyMethodPayload(
                    $context,
                    $normalizer,
                    $reference->targetClassName,
                    $reference->ability,
                );

                if ($policyMethodPayload === null) {
                    continue;
                }

                if ($policyMethodPayload['symbolPatch'] !== null) {
                    $symbols[] = $policyMethodPayload['symbolPatch'];
                }

                if ($policyMethodPayload['definitionPatch'] !== null) {
                    $occurrences[] = $policyMethodPayload['definitionPatch'];
                }

                $occurrences[] = new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                    'range' => $reference->abilityRange->toScipRange(),
                    'symbol' => $policyMethodPayload['symbol'],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));
                $documentationBySymbol[$policyMethodPayload['documentPath']][$policyMethodPayload['symbol']][] =
                    'Laravel authorization ability: ' . $reference->ability;
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

        if (!is_string($filePath) || $filePath === '' || !str_ends_with($filePath, '.php')) {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);
        $resolved = $this->fallbackSymbolResolver->resolveClass($context, $normalizer, $className);

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

    /**
     * @return ?array{
     *   symbol: string,
     *   documentPath: string,
     *   symbolPatch: ?DocumentSymbolPatch,
     *   definitionPatch: ?DocumentOccurrencePatch
     * }
     */
    private function policyMethodPayload(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $targetClass,
        string $ability,
    ): ?array {
        $policyClass = $this->policyResolver->resolve($context->projectRoot, $targetClass);

        if (!is_string($policyClass) || $policyClass === '') {
            return null;
        }

        try {
            $reflection = new ReflectionClass($policyClass);
        } catch (ReflectionException) {
            return null;
        }

        if (!$reflection->hasMethod($ability)) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $resolved = $this->fallbackSymbolResolver->resolveMethod($context, $normalizer, $policyClass, $ability);

        if ($resolved === null) {
            return null;
        }

        return [
            'symbol' => $resolved->symbol,
            'documentPath' => $resolved->documentPath,
            'symbolPatch' => $resolved->symbolPatch,
            'definitionPatch' => $resolved->definitionPatch,
        ];
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }
}

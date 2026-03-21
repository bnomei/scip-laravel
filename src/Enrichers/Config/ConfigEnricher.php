<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Config;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\PhpLiteralMethodCallFinder;
use Bnomei\ScipLaravel\Support\PhpReturnedArrayKeyExtractor;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_key_exists;
use function is_file;
use function pathinfo;
use function str_contains;
use function str_ends_with;
use function substr;

final class ConfigEnricher implements Enricher
{
    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly PhpLiteralMethodCallFinder $methodCallFinder = new PhpLiteralMethodCallFinder(),
        private readonly PhpReturnedArrayKeyExtractor $arrayKeyExtractor = new PhpReturnedArrayKeyExtractor(),
    ) {}

    public function feature(): string
    {
        return 'config';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $configRoot = $context->configPath();

        if (!is_dir($configRoot)) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbols = [];
        $definitions = [];
        $symbolsByKey = [];

        foreach ($this->configFiles($configRoot) as $filePath) {
            $relativeConfigPath = substr($filePath, strlen($configRoot) + 1);
            $prefix = str_replace(DIRECTORY_SEPARATOR, '.', pathinfo($relativeConfigPath, PATHINFO_DIRNAME));
            $prefix = $prefix === '.'
                ? pathinfo($relativeConfigPath, PATHINFO_FILENAME)
                : $prefix . '.' . pathinfo($relativeConfigPath, PATHINFO_FILENAME);

            foreach ($this->arrayKeyExtractor->extract($filePath, $prefix) as $definition) {
                if (array_key_exists($definition->key, $symbolsByKey)) {
                    continue;
                }

                $symbol = $normalizer->config($definition->key);
                $symbolsByKey[$definition->key] = $symbol;
                $relativePath = $context->relativeProjectPath($definition->filePath);
                $symbols[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'display_name' => $definition->key,
                    'kind' => Kind::Key,
                ]));
                $definitions[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                    'range' => $definition->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]));
            }
        }

        if ($symbolsByKey === []) {
            return IndexPatch::empty();
        }

        $references = [];

        foreach ($this->callFinder->find(
            $context->projectRoot,
            ['config'],
            [
                'Config' => ['get'],
                'Illuminate\\Support\\Facades\\Config' => ['get'],
            ],
        ) as $call) {
            if (!array_key_exists($call->literal, $symbolsByKey)) {
                continue;
            }

            $references[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByKey[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]),
            );
        }

        foreach ($this->methodCallFinder->find($context->projectRoot, [
            'app' => ['methods' => ['get'], 'helper_literal' => 'config'],
        ]) as $call) {
            if (!array_key_exists($call->literal, $symbolsByKey)) {
                continue;
            }

            $references[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByKey[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]),
            );
        }

        return new IndexPatch(symbols: $symbols, occurrences: [...$definitions, ...$references]);
    }

    /**
     * @return list<string>
     */
    private function configFiles(string $configRoot): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $configRoot,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $files = [];

        foreach (new RegexIterator($iterator, '/\.php$/i') as $file) {
            $path = $file->getPathname();

            if (!is_file($path) || !str_ends_with($path, '.php')) {
                continue;
            }

            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }
}

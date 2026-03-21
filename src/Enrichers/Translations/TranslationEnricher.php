<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Translations;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\JsonTranslationKeyExtractor;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\PhpReturnedStringLeafExtractor;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Scip\Document;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_values;
use function in_array;
use function is_dir;
use function is_file;
use function is_string;
use function method_exists;
use function pathinfo;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strpos;
use function substr;

final class TranslationEnricher implements Enricher
{
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly BladeDirectiveScanner $bladeScanner = new BladeDirectiveScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly PhpReturnedStringLeafExtractor $phpKeyExtractor = new PhpReturnedStringLeafExtractor(),
        private readonly JsonTranslationKeyExtractor $jsonKeyExtractor = new JsonTranslationKeyExtractor(),
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    public function feature(): string
    {
        return 'translations';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $translationRoots = $this->translationRoots($context);

        if ($translationRoots === []) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $documents = [];
        $symbols = [];
        $definitions = [];
        $references = [];
        $symbolsByPhpKey = [];
        $symbolsByJsonKey = [];

        foreach ($translationRoots as $root) {
            foreach ($this->phpTranslationFiles($root) as $filePath) {
                $prefix = $this->phpTranslationPrefix($root, $filePath);

                if ($prefix === null) {
                    continue;
                }

                foreach ($this->phpKeyExtractor->extract($filePath, $prefix) as $definition) {
                    $symbol =
                        $symbolsByPhpKey[$definition->key] ??= $normalizer->translation('php:' . $definition->key);
                    $relativePath = $context->relativeProjectPath($definition->filePath);
                    $symbols[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: $this->translationSymbol(
                        symbol: $symbol,
                        key: $definition->key,
                        storageFamily: 'php',
                    ));
                    $definitions[] =
                        new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                            'range' => $definition->range->toScipRange(),
                            'symbol' => $symbol,
                            'symbol_roles' => SymbolRole::Definition,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                        ]));
                }
            }

            foreach ($this->jsonTranslationFiles($root) as $filePath) {
                $contents = file_get_contents($filePath);

                if (!is_string($contents) || $contents === '') {
                    continue;
                }

                $documentSymbols = [];
                $documentOccurrences = [];

                foreach ($this->jsonKeyExtractor->extract($filePath) as $definition) {
                    $symbol =
                        $symbolsByJsonKey[$definition->key] ??= $normalizer->translation('json:' . $definition->key);
                    $documentSymbols[] = $this->translationSymbol(
                        symbol: $symbol,
                        key: $definition->key,
                        storageFamily: 'json',
                    );
                    $documentOccurrences[] = new Occurrence([
                        'range' => $definition->range->toScipRange(),
                        'symbol' => $symbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                    ]);
                }

                if ($documentSymbols === []) {
                    continue;
                }

                $documents[] = new Document([
                    'language' => 'json',
                    'relative_path' => $context->relativeProjectPath($filePath),
                    'symbols' => $documentSymbols,
                    'occurrences' => $documentOccurrences,
                    'text' => $contents,
                ]);
            }
        }

        if ($symbols === [] && $documents === []) {
            return IndexPatch::empty();
        }

        foreach ($this->callFinder->find(
            $context->projectRoot,
            ['__', 'trans', 'trans_choice'],
            [
                'Lang' => ['get'],
                'Illuminate\\Support\\Facades\\Lang' => ['get'],
            ],
        ) as $call) {
            $symbol = $this->referenceSymbol($call->literal, $symbolsByPhpKey, $symbolsByJsonKey);

            if ($symbol === null) {
                continue;
            }

            $references[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Translation key: ' . $call->literal],
                ]),
            );
        }

        foreach ($this->bladeDocuments($context, $symbolsByPhpKey, $symbolsByJsonKey) as $document) {
            $documents[] = $document;
        }

        return new IndexPatch(documents: $documents, symbols: $symbols, occurrences: [...$definitions, ...$references]);
    }

    /**
     * @param array<string, string> $symbolsByPhpKey
     * @param array<string, string> $symbolsByJsonKey
     * @return list<Document>
     */
    private function bladeDocuments(LaravelContext $context, array $symbolsByPhpKey, array $symbolsByJsonKey): array
    {
        $documents = [];

        foreach ($this->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $occurrences = [];

            foreach ($this->bladeScanner->scanTranslationReferences($contents) as $reference) {
                $symbol = $this->referenceSymbol($reference->literal, $symbolsByPhpKey, $symbolsByJsonKey);

                if ($symbol === null) {
                    continue;
                }

                $occurrences[] = new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Translation key: ' . $reference->literal],
                ]);
            }

            if ($occurrences === []) {
                continue;
            }

            $documents[] = new Document([
                'language' => 'blade',
                'relative_path' => $context->relativeProjectPath($filePath),
                'occurrences' => $occurrences,
                'text' => $contents,
            ]);
        }

        return $documents;
    }

    /**
     * @param array<string, string> $symbolsByPhpKey
     * @param array<string, string> $symbolsByJsonKey
     */
    private function referenceSymbol(string $literal, array $symbolsByPhpKey, array $symbolsByJsonKey): ?string
    {
        if (str_contains($literal, '.')) {
            return $symbolsByPhpKey[$literal] ?? $symbolsByJsonKey[$literal] ?? null;
        }

        return $symbolsByJsonKey[$literal] ?? $symbolsByPhpKey[$literal] ?? null;
    }

    /**
     * @return list<string>
     */
    private function translationRoots(LaravelContext $context): array
    {
        $roots = [];
        $projectLang = $context->projectPath('lang');

        if (is_dir($projectLang)) {
            $roots[] = $projectLang;
        }

        $legacyLang = $context->projectPath('resources', 'lang');

        if (is_dir($legacyLang) && !in_array($legacyLang, $roots, true)) {
            $roots[] = $legacyLang;
        }

        if (method_exists($context->application, 'langPath')) {
            $resolved = $context->application->langPath();

            if (is_string($resolved) && $resolved !== '' && is_dir($resolved) && !in_array($resolved, $roots, true)) {
                $roots[] = $resolved;
            }
        }

        sort($roots);

        return array_values($roots);
    }

    /**
     * @return list<string>
     */
    private function phpTranslationFiles(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $files = [];

        foreach (new RegexIterator($iterator, '/\.php$/i') as $file) {
            $path = $file->getPathname();

            if (!is_file($path) || !str_ends_with($path, '.php')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function jsonTranslationFiles(string $root): array
    {
        $iterator = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = [];

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (!is_file($path) || !str_ends_with($path, '.json')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    private function phpTranslationPrefix(string $root, string $filePath): ?string
    {
        $relativePath = substr($filePath, strlen($root) + 1);
        $localeSeparator = strpos($relativePath, DIRECTORY_SEPARATOR);

        if ($localeSeparator === false) {
            return null;
        }

        $groupPath = substr($relativePath, $localeSeparator + 1);

        if ($groupPath === '' || !str_ends_with($groupPath, '.php')) {
            return null;
        }

        $directory = pathinfo($groupPath, PATHINFO_DIRNAME);
        $filename = pathinfo($groupPath, PATHINFO_FILENAME);

        if ($directory === '.') {
            return $filename;
        }

        return str_replace(DIRECTORY_SEPARATOR, '.', $directory . DIRECTORY_SEPARATOR . $filename);
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }

    private function translationSymbol(string $symbol, string $key, string $storageFamily): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $symbol,
            'display_name' => $key,
            'kind' => Kind::Key,
            'documentation' => $this->translationDocumentation($key, $storageFamily),
        ]);
    }

    /**
     * @return list<string>
     */
    private function translationDocumentation(string $key, string $storageFamily): array
    {
        return [
            'Laravel translation key: ' . $key,
            $storageFamily === 'json'
                ? 'Translation family: JSON locale file.'
                : 'Translation family: PHP locale group file.',
            $storageFamily === 'json'
                ? 'Cross-locale model: one logical symbol is shared across lang/<locale>.json definitions with the same raw key.'
                : 'Cross-locale model: one logical symbol is shared across lang/<locale>/*.php definitions with the same dotted key.',
        ];
    }
}

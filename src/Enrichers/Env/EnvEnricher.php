<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Env;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Scip\Document;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ksort;
use function preg_match;
use function preg_match_all;
use function strlen;
use function strtolower;
use function trim;

final class EnvEnricher implements Enricher
{
    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
    ) {}

    public function feature(): string
    {
        return 'env';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $envFiles = $this->envFiles($context->projectRoot);

        if ($envFiles === []) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $familyNames = array_map(static fn(array $file): string => $file['basename'], $envFiles);
        $familyLabel = implode(', ', $familyNames);
        $rangerValues = $this->rangerEnvironmentValues($context->rangerSnapshot->environmentVariables);
        $documents = [];
        $referenceOccurrences = [];
        $symbolsByKey = [];
        $hasFamilyDocs = count($envFiles) > 1;

        foreach ($envFiles as $file) {
            $contents = file_get_contents($file['path']);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $definitions = $this->definitionRanges($contents);

            if ($definitions === []) {
                continue;
            }

            $documentSymbols = [];
            $definitionOccurrences = [];
            $relativePath = $context->relativeProjectPath($file['path']);

            foreach ($definitions as $key => $definition) {
                $symbol = $symbolsByKey[$key] ??= $normalizer->env($key);
                $documentation = [];

                if ($hasFamilyDocs) {
                    $documentation[] = 'Env source: ' . $file['basename'];
                    $documentation[] = 'Env family: ' . $familyLabel;

                    $normalizedValue = $file['basename'] === '.env' && array_key_exists($key, $rangerValues)
                        ? $this->normalizedValueDocumentation($key, $rangerValues[$key], true)
                        : $this->normalizedValueDocumentation($key, $definition['value'], false);

                    if ($normalizedValue !== null) {
                        $documentation[] = $normalizedValue;
                    }
                }

                $documentSymbols[$symbol] = new SymbolInformation([
                    'symbol' => $symbol,
                    'display_name' => $key,
                    'kind' => Kind::Key,
                    'documentation' => array_values(array_unique($documentation)),
                ]);
                $definitionOccurrences[] = new Occurrence([
                    'range' => $definition['range']->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]);
            }

            $documents[$relativePath] = new Document([
                'language' => 'dotenv',
                'relative_path' => $relativePath,
                'occurrences' => $definitionOccurrences,
                'symbols' => array_values($documentSymbols),
                'text' => $contents,
            ]);
        }

        if ($documents === [] || $symbolsByKey === []) {
            return IndexPatch::empty();
        }

        foreach ($this->callFinder->find($context->projectRoot, ['env']) as $call) {
            if (!array_key_exists($call->literal, $symbolsByKey)) {
                continue;
            }

            $referenceOccurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByKey[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Env key: ' . $call->literal],
                ]),
            );
        }

        ksort($documents);

        return new IndexPatch(documents: array_values($documents), occurrences: $referenceOccurrences);
    }

    /**
     * @return list<array{basename: string, path: string}>
     */
    private function envFiles(string $projectRoot): array
    {
        $files = [];

        foreach (glob($projectRoot . DIRECTORY_SEPARATOR . '.env*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $basename = basename($path);

            if (preg_match('/^\.env(?:\.[A-Za-z0-9._-]+)?$/', $basename) !== 1) {
                continue;
            }

            $files[$basename . "\0" . $path] = [
                'basename' => $basename,
                'path' => $path,
            ];
        }

        ksort($files);

        return array_values($files);
    }

    /**
     * @param list<object> $environmentVariables
     * @return array<string, mixed>
     */
    private function rangerEnvironmentValues(array $environmentVariables): array
    {
        $values = [];

        foreach ($environmentVariables as $environmentVariable) {
            if (!is_object($environmentVariable) || !is_string($environmentVariable->key ?? null)) {
                continue;
            }

            $values[$environmentVariable->key] = $environmentVariable->value ?? null;
        }

        ksort($values);

        return $values;
    }

    /**
     * @return array<string, array{range: SourceRange, value: string}>
     */
    private function definitionRanges(string $contents): array
    {
        $definitions = [];
        $matched = preg_match_all(
            '/^(?:\s*export\s+)?(?<key>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?<value>.*)$/m',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched !== false) {
            foreach ($matches['key'] ?? [] as $index => [$key, $offset]) {
                if (!is_string($key) || !is_int($offset) || isset($definitions[$key])) {
                    continue;
                }

                $value = $matches['value'][$index][0] ?? '';

                $definitions[$key] = [
                    'range' => SourceRange::fromOffsets($contents, $offset, $offset + strlen($key)),
                    'value' => is_string($value) ? $value : '',
                ];
            }
        }

        return $definitions;
    }

    private function normalizedValueDocumentation(string $key, mixed $value, bool $fromRanger): ?string
    {
        $normalized = $this->normalizedValue($key, $value);

        if ($normalized === null) {
            return null;
        }

        return ($fromRanger ? 'Ranger normalized value: ' : 'Env normalized value: ') . $normalized;
    }

    private function normalizedValue(string $key, mixed $value): ?string
    {
        if ($this->isSecretKey($key)) {
            return '[redacted]';
        }

        if ($value === null) {
            return 'empty';
        }

        if (is_bool($value)) {
            return $value ? 'bool(true)' : 'bool(false)';
        }

        if (is_int($value) || is_float($value)) {
            return 'number(' . (string) $value . ')';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return 'empty';
            }

            if (strtolower($trimmed) === 'true') {
                return 'bool(true)';
            }

            if (strtolower($trimmed) === 'false') {
                return 'bool(false)';
            }

            if (is_numeric($trimmed)) {
                return 'number(' . $trimmed . ')';
            }

            return 'string(len=' . strlen($trimmed) . ')';
        }

        if (is_array($value)) {
            return 'array(size=' . count($value) . ')';
        }

        return null;
    }

    private function isSecretKey(string $key): bool
    {
        return (
            preg_match(
                '/(^|_)(PASSWORD|PASS|SECRET|TOKEN|PRIVATE|CREDENTIAL|COOKIE|SESSION|BEARER|AUTH|SIGNATURE)(_|$)|(^|_)KEY($|_)/i',
                $key,
            ) === 1
        );
    }
}

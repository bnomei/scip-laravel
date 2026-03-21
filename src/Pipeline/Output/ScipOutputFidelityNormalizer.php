<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline\Output;

use Scip\Document;
use Scip\Index;
use Scip\Metadata;
use Scip\PositionEncoding;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\TextEncoding;
use Scip\ToolInfo;

use function array_values;
use function basename;
use function count;
use function iterator_to_array;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function substr;

final class ScipOutputFidelityNormalizer
{
    private const int DEFAULT_POSITION_ENCODING = PositionEncoding::UTF8CodeUnitOffsetFromLineStart;

    public function normalize(Index $index, string $toolVersion): Index
    {
        $this->normalizeMetadata($index, $toolVersion);

        foreach ($this->documentList($index->getDocuments()) as $document) {
            $this->normalizeDocument($document);
        }

        foreach ($this->symbolList($index->getExternalSymbols()) as $symbol) {
            $this->normalizeSymbol($symbol);
        }

        return $index;
    }

    private function normalizeMetadata(Index $index, string $toolVersion): void
    {
        $baseline = $index->getMetadata();
        $baselineToolInfo = $baseline?->getToolInfo();

        $index->setMetadata(new Metadata([
            'version' => $baseline?->getVersion() ?: 1,
            'project_root' => $baseline?->getProjectRoot() ?: '',
            'text_document_encoding' => $baseline?->getTextDocumentEncoding() ?: TextEncoding::UTF8,
            'tool_info' => new ToolInfo([
                'name' => 'scip-laravel',
                'version' => $toolVersion,
                'arguments' => $baselineToolInfo !== null ? $this->stringList($baselineToolInfo->getArguments()) : [],
            ]),
        ]));
    }

    private function normalizeDocument(Document $document): void
    {
        if ($document->getPositionEncoding() === 0) {
            $document->setPositionEncoding(self::DEFAULT_POSITION_ENCODING);
        }

        foreach ($this->symbolList($document->getSymbols()) as $symbol) {
            $this->normalizeSymbol($symbol);
        }
    }

    private function normalizeSymbol(SymbolInformation $symbol): void
    {
        if ($symbol->hasSignatureDocumentation()) {
            $this->normalizeSignatureDocument($symbol->getSignatureDocumentation());
        }

        $parsed = $this->parseScipPhpSymbol($symbol->getSymbol());

        if ($parsed === null) {
            return;
        }

        if ($symbol->getDisplayName() === '' && $parsed['displayName'] !== '') {
            $symbol->setDisplayName($parsed['displayName']);
        }

        if ($symbol->getKind() === 0 && $parsed['kind'] !== 0) {
            $symbol->setKind($parsed['kind']);
        }

        if (count($symbol->getDocumentation()) === 0 && $parsed['documentation'] !== []) {
            $symbol->setDocumentation($parsed['documentation']);
        }

        if (!$symbol->hasSignatureDocumentation() && $parsed['signature'] !== null) {
            $symbol->setSignatureDocumentation(new Document([
                'language' => 'php',
                'text' => $parsed['signature'],
                'position_encoding' => self::DEFAULT_POSITION_ENCODING,
            ]));
        }

        if ($symbol->getEnclosingSymbol() === '' && $parsed['enclosingSymbol'] !== null) {
            $symbol->setEnclosingSymbol($parsed['enclosingSymbol']);
        }
    }

    private function normalizeSignatureDocument(Document $document): void
    {
        if ($document->getLanguage() === '') {
            $document->setLanguage('php');
        }

        if ($document->getPositionEncoding() === 0) {
            $document->setPositionEncoding(self::DEFAULT_POSITION_ENCODING);
        }
    }

    /**
     * @return array{
     *   displayName: string,
     *   kind: int,
     *   documentation: list<string>,
     *   signature: ?string,
     *   enclosingSymbol: ?string
     * }|null
     */
    private function parseScipPhpSymbol(string $symbol): ?array
    {
        if (!str_starts_with($symbol, 'scip-php ')) {
            return null;
        }

        $descriptor = $this->descriptorFromSymbol($symbol);

        if ($descriptor === null || $descriptor === '') {
            return null;
        }

        if (preg_match('/^(.*)#\\$([^.#]+)\\.$/', $descriptor, $matches) === 1) {
            $className = $this->qualifiedName($matches[1]);
            $property = $matches[2];

            return [
                'displayName' => '$' . $property,
                'kind' => Kind::Property,
                'documentation' => ['External PHP property: ' . $className . '::$' . $property],
                'signature' => '$' . $property,
                'enclosingSymbol' => $this->symbolWithDescriptor($symbol, $matches[1] . '#'),
            ];
        }

        if (preg_match('/^(.*)#([^.#()]+)\\(\\)\\.$/', $descriptor, $matches) === 1) {
            $className = $this->qualifiedName($matches[1]);
            $method = $matches[2];

            return [
                'displayName' => $method . '()',
                'kind' => Kind::Method,
                'documentation' => ['External PHP method: ' . $className . '::' . $method . '()'],
                'signature' => $method . '()',
                'enclosingSymbol' => $this->symbolWithDescriptor($symbol, $matches[1] . '#'),
            ];
        }

        if (preg_match('/^(.*)#([^.#()]+)\\.$/', $descriptor, $matches) === 1) {
            $className = $this->qualifiedName($matches[1]);
            $constant = $matches[2];

            return [
                'displayName' => $constant,
                'kind' => Kind::Constant,
                'documentation' => ['External PHP constant: ' . $className . '::' . $constant],
                'signature' => $constant,
                'enclosingSymbol' => $this->symbolWithDescriptor($symbol, $matches[1] . '#'),
            ];
        }

        if (str_ends_with($descriptor, '#')) {
            $className = $this->qualifiedName(substr($descriptor, 0, -1));

            return [
                'displayName' => $this->shortName($className),
                'kind' => Kind::PBClass,
                'documentation' => ['External PHP class: ' . $className],
                'signature' => 'class ' . $this->shortName($className),
                'enclosingSymbol' => null,
            ];
        }

        if (str_ends_with($descriptor, '().')) {
            $functionName = $this->qualifiedName(substr($descriptor, 0, -3));

            return [
                'displayName' => $this->shortName($functionName) . '()',
                'kind' => Kind::PBFunction,
                'documentation' => ['External PHP function: ' . $functionName . '()'],
                'signature' => $this->shortName($functionName) . '()',
                'enclosingSymbol' => null,
            ];
        }

        if (str_ends_with($descriptor, '.')) {
            $constantName = $this->qualifiedName(substr($descriptor, 0, -1));

            return [
                'displayName' => $this->shortName($constantName),
                'kind' => Kind::Constant,
                'documentation' => ['External PHP constant: ' . $constantName],
                'signature' => $this->shortName($constantName),
                'enclosingSymbol' => null,
            ];
        }

        return null;
    }

    private function descriptorFromSymbol(string $symbol): ?string
    {
        $offset = strrpos($symbol, ' ');

        if ($offset === false) {
            return null;
        }

        return substr($symbol, $offset + 1);
    }

    private function symbolWithDescriptor(string $symbol, string $descriptor): ?string
    {
        $offset = strrpos($symbol, ' ');

        if ($offset === false) {
            return null;
        }

        return substr($symbol, 0, $offset + 1) . $descriptor;
    }

    private function qualifiedName(string $descriptor): string
    {
        return str_replace('/', '\\', $descriptor);
    }

    private function shortName(string $qualifiedName): string
    {
        return str_contains($qualifiedName, '\\') ? basename(str_replace('\\', '/', $qualifiedName)) : $qualifiedName;
    }

    /**
     * @template T
     * @param iterable<T>|null $items
     * @return list<T>
     */
    private function documentList(?iterable $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(iterator_to_array($items, false));
    }

    /**
     * @template T
     * @param iterable<T>|null $items
     * @return list<T>
     */
    private function symbolList(?iterable $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(iterator_to_array($items, false));
    }

    /**
     * @param iterable<string>|null $items
     * @return list<string>
     */
    private function stringList(?iterable $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(iterator_to_array($items, false));
    }
}

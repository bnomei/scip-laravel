<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline\Merge;

use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Scip\Diagnostic;
use Scip\Document;
use Scip\Index;
use Scip\Occurrence;
use Scip\PositionEncoding;
use Scip\Relationship;
use Scip\SymbolInformation;

use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function iterator_to_array;
use function ksort;
use function str_ends_with;
use function str_starts_with;
use function strcmp;
use function usort;

final class IndexMerger
{
    private const int DEFAULT_POSITION_ENCODING = PositionEncoding::UTF8CodeUnitOffsetFromLineStart;

    public function merge(Index $baseline, IndexPatch ...$patches): Index
    {
        /** @var array<string, Document> $documents */
        $documents = [];
        /** @var array<string, SymbolInformation> $externalSymbols */
        $externalSymbols = [];
        /** @var array<string, list<SymbolInformation>> $documentSymbols */
        $documentSymbols = [];
        /** @var array<string, list<Occurrence>> $documentOccurrences */
        $documentOccurrences = [];

        foreach ($this->messageList($baseline->getDocuments()) as $document) {
            if ($this->excludedDocumentPath($document->getRelativePath())) {
                continue;
            }

            $documents[$document->getRelativePath()] = $document;
        }

        foreach ($this->messageList($baseline->getExternalSymbols()) as $symbol) {
            $externalSymbols[$symbol->getSymbol()] = $symbol;
        }

        foreach ($patches as $patch) {
            foreach ($patch->documents as $document) {
                $relativePath = $document->getRelativePath();

                if ($this->excludedDocumentPath($relativePath)) {
                    continue;
                }

                if (isset($documents[$relativePath])) {
                    $this->mergeDocument($documents[$relativePath], $document);
                    continue;
                }

                $documents[$relativePath] = $document;
            }

            foreach ($patch->symbols as $symbolPatch) {
                if ($this->excludedDocumentPath($symbolPatch->documentPath)) {
                    continue;
                }

                $documentSymbols[$symbolPatch->documentPath][] = $symbolPatch->symbol;
            }

            foreach ($patch->occurrences as $occurrencePatch) {
                if ($this->excludedDocumentPath($occurrencePatch->documentPath)) {
                    continue;
                }

                $documentOccurrences[$occurrencePatch->documentPath][] = $occurrencePatch->occurrence;
            }

            foreach ($patch->externalSymbols as $symbol) {
                $key = $symbol->getSymbol();

                if (isset($externalSymbols[$key])) {
                    $this->mergeSymbolInformation($externalSymbols[$key], $symbol);
                    continue;
                }

                $externalSymbols[$key] = $symbol;
            }
        }

        foreach ($documentSymbols as $relativePath => $symbols) {
            $document = $this->documentForPath($documents, $relativePath);
            $this->mergeDocumentSymbols($document, $symbols);
        }

        foreach ($documentOccurrences as $relativePath => $occurrences) {
            $document = $this->documentForPath($documents, $relativePath);
            $this->mergeDocumentOccurrences($document, $occurrences);
        }

        ksort($documents);
        ksort($externalSymbols);

        foreach ($documents as $document) {
            $this->normalizeDocument($document);
        }

        return new Index([
            'metadata' => $baseline->getMetadata(),
            'documents' => array_values($documents),
            'external_symbols' => array_values($externalSymbols),
        ]);
    }

    /**
     * @param array<string, Document> $documents
     */
    private function documentForPath(array &$documents, string $relativePath): Document
    {
        if (!isset($documents[$relativePath])) {
            $documents[$relativePath] = new Document([
                'relative_path' => $relativePath,
                'position_encoding' => self::DEFAULT_POSITION_ENCODING,
            ]);
        }

        return $documents[$relativePath];
    }

    private function mergeDocument(Document $existing, Document $incoming): void
    {
        if ($existing->getLanguage() === '' && $incoming->getLanguage() !== '') {
            $existing->setLanguage($incoming->getLanguage());
        }

        if ($existing->getText() === '' && $incoming->getText() !== '') {
            $existing->setText($incoming->getText());
        }

        if ($existing->getPositionEncoding() === 0 && $incoming->getPositionEncoding() !== 0) {
            $existing->setPositionEncoding($incoming->getPositionEncoding());
        }

        $symbols = [];

        foreach ($this->messageList($existing->getSymbols()) as $symbol) {
            $symbols[$symbol->getSymbol()] = $symbol;
        }

        foreach ($this->messageList($incoming->getSymbols()) as $symbol) {
            $key = $symbol->getSymbol();

            if (isset($symbols[$key])) {
                $this->mergeSymbolInformation($symbols[$key], $symbol);
                continue;
            }

            $symbols[$key] = $symbol;
        }

        $existing->setSymbols(array_values($symbols));
        $existing->setOccurrences([
            ...$this->messageList($existing->getOccurrences()),
            ...$this->messageList($incoming->getOccurrences()),
        ]);
    }

    /**
     * @param list<SymbolInformation> $incomingSymbols
     */
    private function mergeDocumentSymbols(Document $document, array $incomingSymbols): void
    {
        $symbols = [];

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (
                $symbol->hasSignatureDocumentation()
                && $symbol->getSignatureDocumentation()->getPositionEncoding() === 0
            ) {
                $signature = $symbol->getSignatureDocumentation();
                $symbol->setSignatureDocumentation(new Document([
                    'language' => $signature->getLanguage(),
                    'text' => $signature->getText(),
                    'relative_path' => $signature->getRelativePath(),
                    'position_encoding' => self::DEFAULT_POSITION_ENCODING,
                ]));
            }

            $symbols[$symbol->getSymbol()] = $symbol;
        }

        foreach ($incomingSymbols as $incoming) {
            $key = $incoming->getSymbol();

            if (isset($symbols[$key])) {
                $this->mergeSymbolInformation($symbols[$key], $incoming);
                continue;
            }

            $symbols[$key] = $incoming;
        }

        $document->setSymbols(array_values($symbols));
    }

    /**
     * @param list<Occurrence> $incomingOccurrences
     */
    private function mergeDocumentOccurrences(Document $document, array $incomingOccurrences): void
    {
        $document->setOccurrences([
            ...$this->messageList($document->getOccurrences()),
            ...$incomingOccurrences,
        ]);
    }

    private function normalizeDocument(Document $document): void
    {
        if ($document->getPositionEncoding() === 0) {
            $document->setPositionEncoding(self::DEFAULT_POSITION_ENCODING);
        }

        $symbols = [];

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            $symbols[$symbol->getSymbol()] = $symbol;
        }

        ksort($symbols);
        $document->setSymbols(array_values($symbols));
        $document->setOccurrences($this->dedupeAndSortOccurrences($this->messageList($document->getOccurrences())));
    }

    private function mergeSymbolInformation(SymbolInformation $existing, SymbolInformation $incoming): void
    {
        if (count($incoming->getDocumentation()) > 0) {
            $documentation = array_values(array_unique([
                ...$this->messageList($existing->getDocumentation()),
                ...$this->messageList($incoming->getDocumentation()),
            ]));
            sort($documentation);
            $existing->setDocumentation($documentation);
        }

        if (count($incoming->getRelationships()) > 0) {
            $relationships = [];

            foreach (array_merge(
                $this->messageList($existing->getRelationships()),
                $this->messageList($incoming->getRelationships()),
            ) as $relationship) {
                $relationships[$this->relationshipKey($relationship)] = $relationship;
            }

            ksort($relationships);
            $existing->setRelationships(array_values($relationships));
        }

        if ($existing->getKind() === 0 && $incoming->getKind() !== 0) {
            $existing->setKind($incoming->getKind());
        }

        if ($existing->getDisplayName() === '' && $incoming->getDisplayName() !== '') {
            $existing->setDisplayName($incoming->getDisplayName());
        }

        if ($incoming->hasSignatureDocumentation()) {
            if (!$existing->hasSignatureDocumentation()) {
                $existing->setSignatureDocumentation($incoming->getSignatureDocumentation());
            } else {
                $existing->setSignatureDocumentation($this->mergeSignatureDocuments(
                    $existing->getSignatureDocumentation(),
                    $incoming->getSignatureDocumentation(),
                ));
            }
        }

        if ($existing->getEnclosingSymbol() === '' && $incoming->getEnclosingSymbol() !== '') {
            $existing->setEnclosingSymbol($incoming->getEnclosingSymbol());
        }
    }

    private function relationshipKey(Relationship $relationship): string
    {
        return implode(':', [
            $relationship->getSymbol(),
            $relationship->getIsReference() ? '1' : '0',
            $relationship->getIsImplementation() ? '1' : '0',
            $relationship->getIsTypeDefinition() ? '1' : '0',
            $relationship->getIsDefinition() ? '1' : '0',
        ]);
    }

    private function mergeSignatureDocuments(Document $existing, Document $incoming): Document
    {
        $text = $this->mergeDocumentText($existing->getText(), $incoming->getText());
        $language = $existing->getLanguage() !== '' ? $existing->getLanguage() : $incoming->getLanguage();

        return new Document([
            'language' => $language,
            'text' => $text,
            'relative_path' => '',
            'position_encoding' => $existing->getPositionEncoding() !== 0
                ? $existing->getPositionEncoding()
                : (
                    $incoming->getPositionEncoding() !== 0
                        ? $incoming->getPositionEncoding()
                        : self::DEFAULT_POSITION_ENCODING
                ),
        ]);
    }

    private function mergeDocumentText(string $existing, string $incoming): string
    {
        if ($existing === '' || $existing === $incoming) {
            return $incoming;
        }

        if ($incoming === '') {
            return $existing;
        }

        $parts = array_values(array_unique([$existing, $incoming]));
        sort($parts);

        return implode("\n", $parts);
    }

    /**
     * @param list<Occurrence> $occurrences
     * @return list<Occurrence>
     */
    private function dedupeAndSortOccurrences(array $occurrences): array
    {
        $deduped = [];

        foreach ($occurrences as $occurrence) {
            $key = $this->logicalOccurrenceKey($occurrence);

            if (!isset($deduped[$key])) {
                $deduped[$key] = $occurrence;
                continue;
            }

            $this->mergeOccurrence($deduped[$key], $occurrence);
        }

        $sorted = [];

        foreach ($deduped as $occurrence) {
            $sorted[] = [
                'key' => $this->occurrenceKey($occurrence),
                'occurrence' => $occurrence,
            ];
        }

        usort($sorted, static fn(array $left, array $right): int => strcmp($left['key'], $right['key']));

        return array_values(array_map(static fn(array $entry): Occurrence => $entry['occurrence'], $sorted));
    }

    private function logicalOccurrenceKey(Occurrence $occurrence): string
    {
        return implode(':', [
            ...array_map('strval', $this->messageList($occurrence->getRange())),
            $occurrence->getSymbol(),
            (string) $occurrence->getSymbolRoles(),
            (string) $occurrence->getSyntaxKind(),
        ]);
    }

    private function mergeOccurrence(Occurrence $existing, Occurrence $incoming): void
    {
        $overrideDocumentation = array_values(array_unique([
            ...$this->messageList($existing->getOverrideDocumentation()),
            ...$this->messageList($incoming->getOverrideDocumentation()),
        ]));
        sort($overrideDocumentation);
        $existing->setOverrideDocumentation($overrideDocumentation);

        $diagnostics = [];

        foreach ([
            ...$this->messageList($existing->getDiagnostics()),
            ...$this->messageList($incoming->getDiagnostics()),
        ] as $diagnostic) {
            $diagnostics[$this->diagnosticKey($diagnostic)] = $diagnostic;
        }

        ksort($diagnostics);
        $existing->setDiagnostics(array_values($diagnostics));

        $existingEnclosing = $this->messageList($existing->getEnclosingRange());
        $incomingEnclosing = $this->messageList($incoming->getEnclosingRange());

        if ($existingEnclosing === [] && $incomingEnclosing !== []) {
            $existing->setEnclosingRange($incomingEnclosing);
            return;
        }

        if (
            $existingEnclosing !== []
            && $incomingEnclosing !== []
            && implode(':', array_map('strval', $incomingEnclosing)) < implode(':', array_map(
                'strval',
                $existingEnclosing,
            ))
        ) {
            $existing->setEnclosingRange($incomingEnclosing);
        }
    }

    private function occurrenceKey(Occurrence $occurrence): string
    {
        return implode(':', [
            ...array_map('strval', $this->messageList($occurrence->getRange())),
            $occurrence->getSymbol(),
            (string) $occurrence->getSymbolRoles(),
            (string) $occurrence->getSyntaxKind(),
            implode("\x1F", array_map('strval', $this->messageList($occurrence->getEnclosingRange()))),
            implode("\x1F", $this->messageList($occurrence->getOverrideDocumentation())),
            implode("\x1E", array_map(fn(Diagnostic $diagnostic): string => $this->diagnosticKey(
                $diagnostic,
            ), $this->messageList($occurrence->getDiagnostics()))),
        ]);
    }

    private function diagnosticKey(Diagnostic $diagnostic): string
    {
        return implode(':', [
            (string) $diagnostic->getSeverity(),
            $diagnostic->getCode(),
            $diagnostic->getMessage(),
            $diagnostic->getSource(),
            implode("\x1F", array_map('strval', $this->messageList($diagnostic->getTags()))),
        ]);
    }

    private function excludedDocumentPath(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'storage/framework/views/') && str_ends_with($relativePath, '.php');
    }

    /**
     * @template T
     * @param iterable<T>|null $messages
     * @return list<T>
     */
    private function messageList(?iterable $messages): array
    {
        if ($messages === null) {
            return [];
        }

        return array_values(iterator_to_array($messages, false));
    }
}

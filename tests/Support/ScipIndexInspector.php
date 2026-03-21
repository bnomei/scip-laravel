<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Support;

use RuntimeException;
use Scip\Diagnostic;
use Scip\Document;
use Scip\Index;
use Scip\Occurrence;
use Scip\SymbolInformation;

use function array_map;
use function array_values;
use function count;
use function file_get_contents;
use function hash;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function ksort;
use function sort;
use function str_contains;
use function str_starts_with;

final class ScipIndexInspector
{
    /**
     * @var array<string, Document>
     */
    private array $documents = [];

    public function __construct(
        private readonly Index $index,
    ) {
        foreach ($this->messageList($index->getDocuments()) as $document) {
            $this->documents[$document->getRelativePath()] = $document;
        }

        ksort($this->documents);
    }

    public static function fromFile(string $path): self
    {
        $bytes = file_get_contents($path);

        if (!is_string($bytes)) {
            throw new RuntimeException('Could not read SCIP index at ' . $path);
        }

        $index = new Index();
        $index->mergeFromString($bytes);

        return new self($index);
    }

    public function documentCount(): int
    {
        return count($this->documents);
    }

    public function metadataToolName(): ?string
    {
        return $this->index
            ->getMetadata()
            ?->getToolInfo()
            ?->getName();
    }

    public function metadataToolVersion(): ?string
    {
        return $this->index
            ->getMetadata()
            ?->getToolInfo()
            ?->getVersion();
    }

    /**
     * @return list<string>
     */
    public function metadataToolArguments(): array
    {
        $toolInfo = $this->index->getMetadata()?->getToolInfo();

        if ($toolInfo === null) {
            return [];
        }

        return $this->stringList($toolInfo->getArguments());
    }

    public function metadataTextDocumentEncoding(): ?int
    {
        return $this->index->getMetadata()?->getTextDocumentEncoding();
    }

    public function countDocumentsWithPrefix(string $prefix): int
    {
        $count = 0;

        foreach (array_keys($this->documents) as $documentPath) {
            if (str_starts_with($documentPath, $prefix)) {
                $count++;
            }
        }

        return $count;
    }

    public function findSymbolByDisplayName(string $documentPath, string $displayName): ?string
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return null;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if ($symbol->getDisplayName() === $displayName) {
                return $symbol->getSymbol();
            }
        }

        return null;
    }

    public function findSymbolKindByDisplayName(string $documentPath, string $displayName): ?int
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return null;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if ($symbol->getDisplayName() === $displayName) {
                return $symbol->getKind();
            }
        }

        return null;
    }

    public function findSymbolEndingWith(string $documentPath, string $suffix): ?string
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return null;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (str_ends_with($symbol->getSymbol(), $suffix)) {
                return $symbol->getSymbol();
            }
        }

        return null;
    }

    public function findExternalSymbolByDisplayName(string $displayName): ?string
    {
        foreach ($this->messageList($this->index->getExternalSymbols()) as $symbol) {
            if ($symbol->getDisplayName() === $displayName) {
                return $symbol->getSymbol();
            }
        }

        return null;
    }

    public function externalSymbolKind(string $displayName): ?int
    {
        foreach ($this->messageList($this->index->getExternalSymbols()) as $symbol) {
            if ($symbol->getDisplayName() === $displayName) {
                return $symbol->getKind();
            }
        }

        return null;
    }

    public function externalSymbolDocumentationContains(string $displayName, string $fragment): bool
    {
        foreach ($this->messageList($this->index->getExternalSymbols()) as $symbol) {
            if ($symbol->getDisplayName() !== $displayName) {
                continue;
            }

            foreach ($this->stringList($symbol->getDocumentation()) as $documentation) {
                if (str_contains($documentation, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function externalSymbolSignatureContains(string $displayName, string $fragment): bool
    {
        foreach ($this->messageList($this->index->getExternalSymbols()) as $symbol) {
            if ($symbol->getDisplayName() !== $displayName || !$symbol->hasSignatureDocumentation()) {
                continue;
            }

            if (str_contains($symbol->getSignatureDocumentation()->getText(), $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function documentPositionEncoding(string $documentPath): ?int
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return null;
        }

        return $document->getPositionEncoding();
    }

    public function hasOccurrence(string $documentPath, string $symbol, int $role): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if ($occurrence->getSymbol() === $symbol && ($occurrence->getSymbolRoles() & $role) === $role) {
                return true;
            }
        }

        return false;
    }

    public function hasOccurrenceWithEnclosingRange(string $documentPath, string $symbol, int $role): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if (
                $occurrence->getSymbol() === $symbol
                && ($occurrence->getSymbolRoles() & $role) === $role
                && count($this->intList($occurrence->getEnclosingRange())) > 0
            ) {
                return true;
            }
        }

        return false;
    }

    public function hasOccurrenceSymbolContaining(string $documentPath, string $needle, int $role): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if (str_contains($occurrence->getSymbol(), $needle) && ($occurrence->getSymbolRoles() & $role) === $role) {
                return true;
            }
        }

        return false;
    }

    public function symbolSignaturePositionEncoding(string $documentPath, string $needle): ?int
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return null;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (!str_contains($symbol->getSymbol(), $needle) || !$symbol->hasSignatureDocumentation()) {
                continue;
            }

            return $symbol->getSignatureDocumentation()->getPositionEncoding();
        }

        return null;
    }

    public function occurrenceOverrideDocumentationContains(
        string $documentPath,
        string $needle,
        int $role,
        string $fragment,
    ): bool {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if (!str_contains($occurrence->getSymbol(), $needle) || ($occurrence->getSymbolRoles() & $role) !== $role) {
                continue;
            }

            foreach ($this->stringList($occurrence->getOverrideDocumentation()) as $documentation) {
                if (str_contains($documentation, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function occurrenceDiagnosticContains(string $documentPath, string $code, string $fragment): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            foreach ($this->messageList($occurrence->getDiagnostics()) as $diagnostic) {
                if ($diagnostic->getCode() === $code && str_contains($diagnostic->getMessage(), $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function countOccurrencesSymbolContaining(string $documentPath, string $needle, ?int $role = null): int
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return 0;
        }

        $count = 0;

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if (!str_contains($occurrence->getSymbol(), $needle)) {
                continue;
            }

            if ($role !== null && ($occurrence->getSymbolRoles() & $role) !== $role) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public function documentHasOccurrenceSymbolContaining(string $documentPath, string $needle): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getOccurrences()) as $occurrence) {
            if (str_contains($occurrence->getSymbol(), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnySymbolDisplayName(string $displayName): bool
    {
        foreach ($this->documents as $document) {
            foreach ($this->messageList($document->getSymbols()) as $symbol) {
                if ($symbol->getDisplayName() === $displayName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function symbolDocumentationContaining(string $documentPath, string $needle): array
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return [];
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (str_contains($symbol->getSymbol(), $needle)) {
                return $this->stringList($symbol->getDocumentation());
            }
        }

        return [];
    }

    public function symbolDocumentationContains(string $documentPath, string $needle, string $fragment): bool
    {
        foreach ($this->symbolDocumentationContaining($documentPath, $needle) as $documentation) {
            if (str_contains($documentation, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function symbolSignatureContains(string $documentPath, string $needle, string $fragment): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (!str_contains($symbol->getSymbol(), $needle) || !$symbol->hasSignatureDocumentation()) {
                continue;
            }

            if (str_contains($symbol->getSignatureDocumentation()->getText(), $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function symbolEnclosingContains(string $documentPath, string $needle, string $fragment): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (str_contains($symbol->getSymbol(), $needle) && str_contains($symbol->getEnclosingSymbol(), $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function symbolHasImplementationRelationship(
        string $documentPath,
        string $needle,
        string $targetFragment,
    ): bool {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (!str_contains($symbol->getSymbol(), $needle)) {
                continue;
            }

            foreach ($this->messageList($symbol->getRelationships()) as $relationship) {
                if ($relationship->getIsImplementation() && str_contains($relationship->getSymbol(), $targetFragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function symbolHasReferenceRelationship(string $documentPath, string $needle, string $targetFragment): bool
    {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (!str_contains($symbol->getSymbol(), $needle)) {
                continue;
            }

            foreach ($this->messageList($symbol->getRelationships()) as $relationship) {
                if ($relationship->getIsReference() && str_contains($relationship->getSymbol(), $targetFragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function symbolHasTypeDefinitionRelationship(
        string $documentPath,
        string $needle,
        string $targetFragment,
    ): bool {
        $document = $this->documents[$documentPath] ?? null;

        if (!$document instanceof Document) {
            return false;
        }

        foreach ($this->messageList($document->getSymbols()) as $symbol) {
            if (!str_contains($symbol->getSymbol(), $needle)) {
                continue;
            }

            foreach ($this->messageList($symbol->getRelationships()) as $relationship) {
                if ($relationship->getIsTypeDefinition() && str_contains($relationship->getSymbol(), $targetFragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalize(): array
    {
        $documents = [];
        $focusDocuments = [];

        foreach ($this->documents as $relativePath => $document) {
            $symbols = array_map(fn(SymbolInformation $symbol): array => [
                'symbol' => $symbol->getSymbol(),
                'display_name' => $symbol->getDisplayName(),
                'kind' => $symbol->getKind(),
                'documentation' => $this->stringList($symbol->getDocumentation()),
                'relationships' => array_map(fn($relationship): array => [
                    'symbol' => $relationship->getSymbol(),
                    'is_reference' => $relationship->getIsReference(),
                    'is_implementation' => $relationship->getIsImplementation(),
                    'is_type_definition' => $relationship->getIsTypeDefinition(),
                    'is_definition' => $relationship->getIsDefinition(),
                ], $this->messageList($symbol->getRelationships())),
                'signature' => $symbol->hasSignatureDocumentation()
                    ? $symbol->getSignatureDocumentation()->getText()
                    : null,
                'enclosing_symbol' => $symbol->getEnclosingSymbol(),
            ], $this->messageList($document->getSymbols()));
            $occurrences = array_map(fn(Occurrence $occurrence): array => [
                'range' => $this->intList($occurrence->getRange()),
                'symbol' => $occurrence->getSymbol(),
                'symbol_roles' => $occurrence->getSymbolRoles(),
                'syntax_kind' => $occurrence->getSyntaxKind(),
                'override_documentation' => $this->stringList($occurrence->getOverrideDocumentation()),
                'diagnostics' => array_map(fn(Diagnostic $diagnostic): array => [
                    'severity' => $diagnostic->getSeverity(),
                    'code' => $diagnostic->getCode(),
                    'message' => $diagnostic->getMessage(),
                    'source' => $diagnostic->getSource(),
                    'tags' => $this->intList($diagnostic->getTags()),
                ], $this->messageList($occurrence->getDiagnostics())),
                'enclosing_range' => $this->intList($occurrence->getEnclosingRange()),
            ], $this->messageList($document->getOccurrences()));

            $documents[] = [
                'relative_path' => $relativePath,
                'language' => $document->getLanguage(),
                'text_sha256' => hash('sha256', $document->getText()),
                'symbol_count' => count($symbols),
                'occurrence_count' => count($occurrences),
                'symbols_sha256' => hash('sha256', json_encode($symbols, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'occurrences_sha256' => hash('sha256', json_encode(
                    $occurrences,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )),
            ];

            if ($this->isFocusDocument($relativePath)) {
                $focusDocuments[] = [
                    'relative_path' => $relativePath,
                    'symbols' => $symbols,
                    'occurrences' => $occurrences,
                ];
            }
        }

        $externalSymbols = array_map(fn(SymbolInformation $symbol): array => [
            'symbol' => $symbol->getSymbol(),
            'display_name' => $symbol->getDisplayName(),
            'kind' => $symbol->getKind(),
            'documentation' => $this->stringList($symbol->getDocumentation()),
            'relationships' => array_map(fn($relationship): array => [
                'symbol' => $relationship->getSymbol(),
                'is_reference' => $relationship->getIsReference(),
                'is_implementation' => $relationship->getIsImplementation(),
                'is_type_definition' => $relationship->getIsTypeDefinition(),
                'is_definition' => $relationship->getIsDefinition(),
            ], $this->messageList($symbol->getRelationships())),
            'signature' => $symbol->hasSignatureDocumentation()
                ? $symbol->getSignatureDocumentation()->getText()
                : null,
            'enclosing_symbol' => $symbol->getEnclosingSymbol(),
        ], $this->messageList($this->index->getExternalSymbols()));

        sort($externalSymbols);

        return [
            'documents' => $documents,
            'external_symbol_count' => count($externalSymbols),
            'external_symbols_sha256' => hash('sha256', json_encode(
                array_values($externalSymbols),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )),
            'focus_documents' => $focusDocuments,
        ];
    }

    private function isFocusDocument(string $relativePath): bool
    {
        if ($relativePath === '.env') {
            return true;
        }

        foreach ([
            'app/Events/Acceptance',
            'app/Http/Controllers/Acceptance',
            'app/Support/Acceptance',
            'config/scip-acceptance.php',
            'lang/en/scip-acceptance.php',
            'lang/en.json',
            'resources/js/Pages/Acceptance/',
            'resources/views/acceptance/',
            'resources/views/components/acceptance/',
            'resources/views/components/acceptance/banner.blade.php',
            'resources/views/livewire/acceptance-attributes-volt.blade.php',
            'resources/views/livewire/acceptance-attributes.blade.php',
            'resources/views/livewire/acceptance-browser-events.blade.php',
            'resources/views/livewire/acceptance-computed-model.blade.php',
            'resources/views/livewire/acceptance-directive.blade.php',
            'resources/views/livewire/acceptance-explicit-route-bound.blade.php',
            'resources/views/livewire/acceptance-event-source.blade.php',
            'resources/views/livewire/acceptance-event-target.blade.php',
            'resources/views/livewire/acceptance-model.blade.php',
            'resources/views/livewire/acceptance-reactive-child.blade.php',
            'resources/views/livewire/acceptance-realtime.blade.php',
            'resources/views/livewire/acceptance-route-bound.blade.php',
            'resources/views/livewire/acceptance-unsupported-route-bound.blade.php',
            'resources/views/livewire/acceptance-validation.blade.php',
            'resources/views/livewire/posts.blade.php',
            'resources/views/livewire/team/member.blade.php',
            'app/View/Components/Acceptance/Banner.php',
            'resources/views/flux/icon/github.blade.php',
            'resources/views/layouts/acceptance-shell.blade.php',
            'app/Livewire/AcceptanceValidation.php',
            'app/Livewire/AcceptanceBrowserEvents.php',
            'app/Livewire/AcceptanceChildInput.php',
            'app/Livewire/AcceptanceEventSource.php',
            'app/Livewire/AcceptanceEventTarget.php',
            'app/Livewire/AcceptanceExplicitRouteBound.php',
            'app/Livewire/AcceptanceReactiveChild.php',
            'app/Livewire/Forms/AcceptanceValidationForm.php',
            'app/Livewire/AcceptanceDirective.php',
            'app/Livewire/AcceptanceAttributes.php',
            'app/Livewire/AcceptanceRealtime.php',
            'app/Contracts/AcceptancePusher.php',
            'app/Http/Requests/AcceptanceValidatedRequest.php',
            'app/Models/Member.php',
            'app/Services/AcceptanceConsumer.php',
            'app/Services/AcceptancePusherService.php',
            'tests/Fakes/AcceptancePusherFake.php',
            'routes/acceptance.php',
            'routes/channels.php',
        ] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     * @param iterable<T> $field
     * @return list<T>
     */
    private function messageList(iterable $field): array
    {
        return array_values(iterator_to_array($field, false));
    }

    /**
     * @param iterable<int> $field
     * @return list<int>
     */
    private function intList(iterable $field): array
    {
        return array_values(iterator_to_array($field, false));
    }

    /**
     * @param iterable<string> $field
     * @return list<string>
     */
    private function stringList(iterable $field): array
    {
        $values = array_values(iterator_to_array($field, false));
        sort($values);

        return $values;
    }
}

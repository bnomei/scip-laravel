<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Pipeline;

use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Pipeline\Merge\IndexMerger;
use PHPUnit\Framework\TestCase;
use Scip\Diagnostic;
use Scip\Document;
use Scip\Index;
use Scip\Occurrence;
use Scip\PositionEncoding;
use Scip\Severity;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function iterator_to_array;

final class IndexMergerTest extends TestCase
{
    public function test_compiled_blade_cache_documents_are_excluded_from_the_merged_index(): void
    {
        $baseline = new Index([
            'documents' => [
                new Document([
                    'relative_path' => 'resources/views/welcome.blade.php',
                    'language' => 'blade',
                ]),
                new Document([
                    'relative_path' => 'storage/framework/views/compiled.php',
                    'language' => 'php',
                ]),
            ],
        ]);

        $merged = (new IndexMerger())->merge($baseline, new IndexPatch(documents: [
            new Document([
                'relative_path' => 'storage/framework/views/compiled-extra.php',
                'language' => 'php',
            ]),
        ]));

        $paths = [];

        foreach ($merged->getDocuments() as $document) {
            $paths[] = $document->getRelativePath();
        }

        self::assertSame(['resources/views/welcome.blade.php'], $paths);
    }

    public function test_occurrence_dedupe_merges_richer_metadata_into_one_logical_occurrence(): void
    {
        $baseline = new Index([
            'documents' => [
                new Document([
                    'relative_path' => 'resources/views/example.blade.php',
                    'occurrences' => [
                        new Occurrence([
                            'range' => [0, 1, 8],
                            'symbol' => 'laravel composer demo 1 routes/`acceptance.route`.',
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                            'override_documentation' => ['Laravel route: acceptance.route'],
                            'diagnostics' => [
                                new Diagnostic([
                                    'severity' => Severity::Warning,
                                    'code' => 'blade.dynamic-view-target',
                                    'message' => 'Unsupported dynamic Blade view target.',
                                    'source' => 'scip-laravel',
                                ]),
                            ],
                            'enclosing_range' => [0, 0, 20],
                        ]),
                    ],
                ]),
            ],
        ]);

        $merged = (new IndexMerger())->merge($baseline, new IndexPatch(occurrences: [
            new \Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch(documentPath: 'resources/views/example.blade.php', occurrence: new Occurrence([
                'range' => [0, 1, 8],
                'symbol' => 'laravel composer demo 1 routes/`acceptance.route`.',
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
                'override_documentation' => ['Laravel route: acceptance.route'],
                'diagnostics' => [
                    new Diagnostic([
                        'severity' => Severity::Warning,
                        'code' => 'blade.dynamic-view-target',
                        'message' => 'Unsupported dynamic Blade view target.',
                        'source' => 'scip-laravel',
                    ]),
                ],
                'enclosing_range' => [0, 0, 20],
            ])),
            new \Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch(documentPath: 'resources/views/example.blade.php', occurrence: new Occurrence([
                'range' => [0, 1, 8],
                'symbol' => 'laravel composer demo 1 routes/`acceptance.route`.',
                'symbol_roles' => SymbolRole::ReadAccess,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
                'override_documentation' => ['Laravel route: acceptance.other'],
                'diagnostics' => [
                    new Diagnostic([
                        'severity' => Severity::Warning,
                        'code' => 'blade.dynamic-component',
                        'message' => 'Unsupported dynamic Blade component target.',
                        'source' => 'scip-laravel',
                    ]),
                ],
                'enclosing_range' => [0, 0, 24],
            ])),
        ]));

        $documents = iterator_to_array($merged->getDocuments(), false);
        self::assertCount(1, $documents);

        $occurrences = iterator_to_array($documents[0]->getOccurrences(), false);
        self::assertCount(1, $occurrences);
        self::assertSame(
            ['Laravel route: acceptance.other', 'Laravel route: acceptance.route'],
            iterator_to_array($occurrences[0]->getOverrideDocumentation(), false),
        );
        self::assertSame([0, 0, 20], iterator_to_array($occurrences[0]->getEnclosingRange(), false));
        self::assertCount(2, iterator_to_array($occurrences[0]->getDiagnostics(), false));
    }

    public function test_document_symbols_are_merged_and_normalized_after_patching(): void
    {
        $baseline = new Index([
            'documents' => [
                new Document([
                    'relative_path' => 'app/Http/Controllers/ExampleController.php',
                    'symbols' => [
                        new SymbolInformation([
                            'symbol' => 'laravel composer demo 1 zeta',
                            'documentation' => ['Zeta docs'],
                        ]),
                        new SymbolInformation([
                            'symbol' => 'laravel composer demo 1 alpha',
                            'documentation' => ['Alpha baseline'],
                        ]),
                    ],
                ]),
            ],
        ]);

        $merged = (new IndexMerger())->merge($baseline, new IndexPatch(documents: [
            new Document([
                'relative_path' => 'app/Http/Controllers/ExampleController.php',
                'symbols' => [
                    new SymbolInformation([
                        'symbol' => 'laravel composer demo 1 alpha',
                        'documentation' => ['Alpha incoming'],
                    ]),
                    new SymbolInformation([
                        'symbol' => 'laravel composer demo 1 beta',
                        'documentation' => ['Beta docs'],
                    ]),
                ],
            ]),
        ]));

        $documents = iterator_to_array($merged->getDocuments(), false);
        self::assertCount(1, $documents);

        $symbols = iterator_to_array($documents[0]->getSymbols(), false);
        self::assertSame(
            [
                'laravel composer demo 1 alpha',
                'laravel composer demo 1 beta',
                'laravel composer demo 1 zeta',
            ],
            array_map(static fn(SymbolInformation $symbol): string => $symbol->getSymbol(), $symbols),
        );
        self::assertSame(
            ['Alpha baseline', 'Alpha incoming'],
            iterator_to_array($symbols[0]->getDocumentation(), false),
        );
    }

    public function test_patched_documents_get_default_position_encoding(): void
    {
        $merged = (new IndexMerger())->merge(
            new Index(['documents' => []]),
            new IndexPatch(documents: [
                new Document([
                    'relative_path' => 'app/Support/EncodingProbe.php',
                    'language' => 'php',
                    'symbols' => [
                        new SymbolInformation([
                            'symbol' => 'laravel composer demo 1 encoding-probe',
                            'signature_documentation' => new Document([
                                'language' => 'php',
                                'text' => 'probe(): void',
                            ]),
                        ]),
                    ],
                ]),
            ]),
        );

        $documents = iterator_to_array($merged->getDocuments(), false);
        self::assertCount(1, $documents);
        self::assertSame(PositionEncoding::UTF8CodeUnitOffsetFromLineStart, $documents[0]->getPositionEncoding());
    }
}

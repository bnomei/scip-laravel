<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Pipeline\Output;

use Bnomei\ScipLaravel\Pipeline\Output\ScipOutputFidelityNormalizer;
use PHPUnit\Framework\TestCase;
use Scip\Document;
use Scip\Index;
use Scip\Metadata;
use Scip\PositionEncoding;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\TextEncoding;
use Scip\ToolInfo;

final class ScipOutputFidelityNormalizerTest extends TestCase
{
    public function test_normalizer_rewrites_metadata_and_enriches_scip_php_symbols(): void
    {
        $index = new Index([
            'metadata' => new Metadata([
                'version' => 1,
                'project_root' => '/tmp/demo',
                'text_document_encoding' => TextEncoding::UTF8,
                'tool_info' => new ToolInfo([
                    'name' => 'scip-php',
                    'version' => '0.0.0',
                    'arguments' => ['--feature=routes'],
                ]),
            ]),
            'documents' => [
                new Document([
                    'relative_path' => 'app/Support/Probe.php',
                    'language' => 'php',
                    'symbols' => [
                        new SymbolInformation([
                            'symbol' => 'scip-php composer demo/app dev-main App/Support/Probe#run().',
                        ]),
                    ],
                ]),
            ],
            'external_symbols' => [
                new SymbolInformation([
                    'symbol' => 'scip-php composer laravel/framework v12.0.0 Illuminate/Broadcasting/PrivateChannel#',
                ]),
            ],
        ]);

        $normalized = (new ScipOutputFidelityNormalizer())->normalize($index, '1.2.3');

        self::assertSame('scip-laravel', $normalized->getMetadata()?->getToolInfo()?->getName());
        self::assertSame('1.2.3', $normalized->getMetadata()?->getToolInfo()?->getVersion());
        self::assertSame(
            ['--feature=routes'],
            iterator_to_array($normalized->getMetadata()?->getToolInfo()?->getArguments() ?? [], false),
        );

        $documents = iterator_to_array($normalized->getDocuments(), false);
        self::assertCount(1, $documents);
        self::assertSame(PositionEncoding::UTF8CodeUnitOffsetFromLineStart, $documents[0]->getPositionEncoding());

        $documentSymbols = iterator_to_array($documents[0]->getSymbols(), false);
        self::assertCount(1, $documentSymbols);
        self::assertSame('run()', $documentSymbols[0]->getDisplayName());
        self::assertSame(Kind::Method, $documentSymbols[0]->getKind());
        self::assertSame(
            'scip-php composer demo/app dev-main App/Support/Probe#',
            $documentSymbols[0]->getEnclosingSymbol(),
        );
        self::assertTrue($documentSymbols[0]->hasSignatureDocumentation());
        self::assertSame('run()', $documentSymbols[0]->getSignatureDocumentation()->getText());
        self::assertSame(
            PositionEncoding::UTF8CodeUnitOffsetFromLineStart,
            $documentSymbols[0]->getSignatureDocumentation()->getPositionEncoding(),
        );

        $externalSymbols = iterator_to_array($normalized->getExternalSymbols(), false);
        self::assertCount(1, $externalSymbols);
        self::assertSame('PrivateChannel', $externalSymbols[0]->getDisplayName());
        self::assertSame(Kind::PBClass, $externalSymbols[0]->getKind());
        self::assertSame(
            ['External PHP class: Illuminate\\Broadcasting\\PrivateChannel'],
            iterator_to_array($externalSymbols[0]->getDocumentation(), false),
        );
        self::assertTrue($externalSymbols[0]->hasSignatureDocumentation());
        self::assertSame('class PrivateChannel', $externalSymbols[0]->getSignatureDocumentation()->getText());
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Env;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Config\RuntimeConfiguration;
use Bnomei\ScipLaravel\Config\RuntimeMode;
use Bnomei\ScipLaravel\Enrichers\Env\EnvEnricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Pipeline\Merge\IndexMerger;
use Bnomei\ScipLaravel\Support\DiagnosticsSink;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Bnomei\ScipLaravel\Tests\Support\ScipIndexInspector;
use Laravel\Surveyor\Analyzer\Analyzer;
use PHPUnit\Framework\TestCase;
use Scip\Index;
use Scip\SymbolRole;

use function array_map;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class EnvEnricherTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_collect_indexes_env_family_documents_and_references_without_leaking_raw_values(): void
    {
        $root = $this->tempDirectory();

        try {
            $this->writeComposerMetadata($root);
            file_put_contents($root . '/.env', implode("\n", [
                'APP_NAME=probe-app',
                'APP_URL=http://probe.test',
                'SECRET_TOKEN=topsecret',
                '',
            ]));
            file_put_contents($root . '/.env.example', implode("\n", [
                'APP_NAME=example-app',
                'APP_URL=http://example.test',
                'SECRET_TOKEN=placeholder-secret',
                '',
            ]));
            $this->writePhpProbe($root);

            $index = $this->inspect((new EnvEnricher())->collect($this->context($root, [
                'APP_NAME' => 'probe-app',
                'APP_URL' => 'http://probe.test',
                'SECRET_TOKEN' => 'topsecret',
            ])));

            self::assertSame(3, $index->documentCount());

            $envNameSymbol = $index->findSymbolByDisplayName('.env', 'APP_NAME');
            $envExampleNameSymbol = $index->findSymbolByDisplayName('.env.example', 'APP_NAME');
            $secretSymbol = $index->findSymbolByDisplayName('.env', 'SECRET_TOKEN');

            self::assertNotNull($envNameSymbol);
            self::assertSame($envNameSymbol, $envExampleNameSymbol);
            self::assertNotNull($secretSymbol);
            self::assertTrue($index->hasOccurrence('app/EnvProbe.php', $envNameSymbol, SymbolRole::ReadAccess));
            self::assertTrue($index->hasOccurrence('app/EnvProbe.php', $secretSymbol, SymbolRole::ReadAccess));

            $envDocs = implode("\n", $index->symbolDocumentationContaining('.env', 'APP_NAME'));
            $exampleDocs = implode("\n", $index->symbolDocumentationContaining('.env.example', 'APP_NAME'));
            $secretDocs = implode("\n", $index->symbolDocumentationContaining('.env', 'SECRET_TOKEN'));

            self::assertStringContainsString('Env source: .env', $envDocs);
            self::assertStringContainsString('Env family: .env, .env.example', $envDocs);
            self::assertStringContainsString('Ranger normalized value: string(len=9)', $envDocs);
            self::assertStringContainsString('Env source: .env.example', $exampleDocs);
            self::assertStringContainsString('Env normalized value: string(len=11)', $exampleDocs);
            self::assertStringContainsString('[redacted]', $secretDocs);
            self::assertStringNotContainsString('topsecret', $envDocs . "\n" . $secretDocs);
            self::assertStringNotContainsString('placeholder-secret', $exampleDocs);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_single_env_file_keeps_symbol_documentation_empty(): void
    {
        $root = $this->tempDirectory();

        try {
            $this->writeComposerMetadata($root);
            file_put_contents($root . '/.env', implode("\n", [
                'APP_NAME=probe-app',
                'SECRET_TOKEN=topsecret',
                '',
            ]));
            $this->writePhpProbe($root);

            $index = $this->inspect((new EnvEnricher())->collect($this->context($root, [
                'APP_NAME' => 'probe-app',
                'SECRET_TOKEN' => 'topsecret',
            ])));

            self::assertSame(2, $index->documentCount());
            $symbol = $index->findSymbolByDisplayName('.env', 'APP_NAME');
            self::assertNotNull($symbol);
            self::assertSame([], $index->symbolDocumentationContaining('.env', 'APP_NAME'));
            self::assertTrue($index->hasOccurrence('app/EnvProbe.php', $symbol, SymbolRole::ReadAccess));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function context(string $root, array $environmentVariables): LaravelContext
    {
        $analyzer = $this->createStub(Analyzer::class);

        return new LaravelContext(
            projectRoot: $root,
            config: new RuntimeConfiguration(
                configPath: $root . '/config/scip-laravel.php',
                configLoaded: false,
                outputPath: $root . '/build/output.scip',
                mode: RuntimeMode::Full,
                strict: false,
                features: ['env'],
            ),
            mode: RuntimeMode::Full,
            application: new class($root) {
                public function __construct(
                    private readonly string $root,
                ) {}

                public function path(string $path = ''): string
                {
                    return $this->join($this->root . '/app', $path);
                }

                public function configPath(string $path = ''): string
                {
                    return $this->join($this->root . '/config', $path);
                }

                private function join(string $base, string $path): string
                {
                    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
                }
            },
            consoleKernel: null,
            analyzer: $analyzer,
            surveyor: new SurveyorMetadataRepository($analyzer),
            rangerSnapshot: $this->rangerSnapshot($environmentVariables),
            baselineIndex: new Index(),
            diagnostics: new DiagnosticsSink(),
            enabledFeatures: ['env'],
        );
    }

    private function rangerSnapshot(array $environmentVariables): \Bnomei\ScipLaravel\Application\RangerSnapshot
    {
        return new \Bnomei\ScipLaravel\Application\RangerSnapshot(
            routes: [],
            models: [],
            enums: [],
            broadcastEvents: [],
            broadcastChannels: [],
            environmentVariables: array_map(
                static fn(string $key, mixed $value): object => (object) ['key' => $key, 'value' => $value],
                array_keys($environmentVariables),
                array_values($environmentVariables),
            ),
            inertiaSharedData: [],
            inertiaComponents: [],
        );
    }

    private function inspect(IndexPatch $patch): ScipIndexInspector
    {
        $merged = (new IndexMerger())->merge(new Index(), $patch);
        $path = tempnam(sys_get_temp_dir(), 'env-index-');

        if (!is_string($path)) {
            self::fail('Could not create a temporary SCIP file.');
        }

        file_put_contents($path, $merged->serializeToString());

        try {
            return ScipIndexInspector::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    private function writeComposerMetadata(string $root): void
    {
        mkdir($root . '/vendor/composer', 0777, true);
        file_put_contents($root . '/vendor/composer/installed.php', <<<'PHP'
            <?php return ['root' => ['name' => 'probe/app', 'version' => '1.0.0']];
            PHP);
    }

    private function writePhpProbe(string $root): void
    {
        mkdir($root . '/app', 0777, true);
        file_put_contents($root . '/app/EnvProbe.php', <<<'PHP'
            <?php

            env('APP_NAME');
            env('SECRET_TOKEN');
            PHP);
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/env-enricher-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}

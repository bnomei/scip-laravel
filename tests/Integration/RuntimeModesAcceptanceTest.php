<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Integration;

use Bnomei\ScipLaravel\Console\IndexCommand;
use Bnomei\ScipLaravel\Tests\Support\AcceptanceTestCase;
use Bnomei\ScipLaravel\Tests\Support\FluxAcceptanceFixture;
use Bnomei\ScipLaravel\Tests\Support\ThrowingEnricher;
use RuntimeException;

use function fclose;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;

final class RuntimeModesAcceptanceTest extends AcceptanceTestCase
{
    public function test_safe_and_full_modes_cover_local_only_routes_and_restore_environment(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousServer = $_SERVER['APP_ENV'] ?? null;

        try {
            $fullResult = $this->executeCliIndex('runtime-full.scip', mode: 'full', features: ['routes']);
            $fullIndex = $this->loadIndex('runtime-full.scip');
            self::assertSame(0, $fullResult->exitCode);
            $_ENV['APP_ENV'] = 'acceptance-before';
            $_SERVER['APP_ENV'] = 'acceptance-before';
            $safeResult = $this->executeCliIndex('runtime-safe.scip', mode: 'safe', features: ['routes']);
            $safeIndex = $this->loadIndex('runtime-safe.scip');
            self::assertSame(0, $safeResult->exitCode);

            $this->assertDefinitionAndReference(
                $fullIndex,
                'routes/acceptance.php',
                'acceptance.full-only',
                'app/Support/AcceptanceRouteProbe.php',
            );
            self::assertNull($safeIndex->findSymbolByDisplayName('routes/acceptance.php', 'acceptance.full-only'));
            self::assertFalse($safeIndex->documentHasOccurrenceSymbolContaining(
                'app/Support/AcceptanceRouteProbe.php',
                'acceptance.full-only',
            ));
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previousServer;
            }
        }

        self::assertSame($previousEnv, $_ENV['APP_ENV'] ?? null);
        self::assertSame($previousServer, $_SERVER['APP_ENV'] ?? null);
    }

    public function test_omitting_mode_defaults_to_full(): void
    {
        $outputPath = FluxAcceptanceFixture::outputPath('runtime-default-full.scip');
        $script = sprintf(<<<'PHP'
            require 'vendor/autoload.php';

            $command = new Bnomei\ScipLaravel\Console\IndexCommand();

            exit($command->run([
                'bin/scip-laravel',
                '--feature=routes',
                '--output=%s',
                '%s',
            ]));
            PHP, $outputPath, FluxAcceptanceFixture::prepare());
        $process = proc_open(
            ['php', '-r', $script],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            FluxAcceptanceFixture::repositoryRoot(),
        );

        self::assertTrue(is_resource($process));
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process));

        $index = $this->loadIndex('runtime-default-full.scip');

        $this->assertDefinitionAndReference(
            $index,
            'routes/acceptance.php',
            'acceptance.full-only',
            'app/Support/AcceptanceRouteProbe.php',
        );
    }

    public function test_fail_open_mode_writes_output_and_emits_warning(): void
    {
        $application = $this->makeApplicationWithEnrichers([
            new ThrowingEnricher('views'),
        ]);

        $result = $this->executeIndex(
            'runtime-fail-open.scip',
            mode: 'safe',
            strict: false,
            features: ['views'],
            application: $application,
        );

        self::assertFileExists(FluxAcceptanceFixture::root() . '/build/runtime-fail-open.scip');
        self::assertCount(1, $result->warnings);
        self::assertSame('enricher-failed', $result->warnings[0]->code);
    }

    public function test_strict_mode_throws_and_command_returns_non_zero(): void
    {
        $application = $this->makeApplicationWithEnrichers([
            new ThrowingEnricher('views'),
        ]);
        try {
            $this->executeIndex(
                'runtime-strict.scip',
                mode: 'safe',
                strict: true,
                features: ['views'],
                application: $application,
            );
            self::fail('Expected a strict-mode exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Acceptance failure injection.', $exception->getMessage());
        }

        $script = sprintf(<<<'PHP'
            require 'vendor/autoload.php';

            $application = new Bnomei\ScipLaravel\Application\IndexApplication(
                pipeline: new Bnomei\ScipLaravel\Pipeline\IndexPipeline(
                    enrichers: [new Bnomei\ScipLaravel\Tests\Support\ThrowingEnricher('views')],
                ),
            );
            $command = new Bnomei\ScipLaravel\Console\IndexCommand(application: $application);

            exit($command->run([
                'bin/scip-laravel',
                '--mode=safe',
                '--strict',
                '--feature=views',
                '--output=%s',
                '%s',
            ]));
            PHP, FluxAcceptanceFixture::outputPath('runtime-strict-command.scip'), FluxAcceptanceFixture::prepare());
        $process = proc_open(
            ['php', '-r', $script],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            FluxAcceptanceFixture::repositoryRoot(),
        );

        self::assertTrue(is_resource($process));
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
    }
}

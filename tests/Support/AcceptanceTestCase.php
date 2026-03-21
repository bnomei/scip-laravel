<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Support;

use Bnomei\ScipLaravel\Application\ExecutionResult;
use Bnomei\ScipLaravel\Application\IndexApplication;
use Bnomei\ScipLaravel\Console\CliInput;
use Bnomei\ScipLaravel\Pipeline\IndexPipeline;
use PHPUnit\Framework\TestCase;
use Scip\SymbolRole;
use Throwable;

use function fclose;
use function implode;
use function is_resource;
use function proc_close;
use function proc_open;
use function set_error_handler;
use function set_exception_handler;
use function sprintf;
use function stream_get_contents;

abstract class AcceptanceTestCase extends TestCase
{
    protected function executeCliIndex(string $outputName, string $mode = 'full', array $features = []): CliRunResult
    {
        return $this->executeCliIndexToPath(FluxAcceptanceFixture::outputPath($outputName), $mode, $features);
    }

    protected function executeCliIndexToPath(
        string $outputPath,
        string $mode = 'full',
        array $features = [],
    ): CliRunResult {
        $fixtureRoot = FluxAcceptanceFixture::prepare();
        $command = [
            'php',
            'bin/scip-laravel',
            '--mode=' . $mode,
            '--output=' . $outputPath,
        ];

        if ($features !== []) {
            $command[] = '--feature=' . implode(',', $features);
        }

        $command[] = $fixtureRoot;

        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            FluxAcceptanceFixture::repositoryRoot(),
        );

        self::assertTrue(is_resource($process));
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return new CliRunResult(exitCode: proc_close($process), stdout: $stdout, stderr: $stderr);
    }

    protected function executeIndex(
        string $outputName,
        string $mode = 'full',
        bool $strict = false,
        array $features = [],
        ?IndexApplication $application = null,
    ): ExecutionResult {
        $fixtureRoot = FluxAcceptanceFixture::prepare();
        $outputPath = FluxAcceptanceFixture::outputPath($outputName);
        $application ??= new IndexApplication();
        $previousErrorHandler = set_error_handler(static fn() => false);
        restore_error_handler();
        $previousExceptionHandler = set_exception_handler(static function (Throwable $throwable): void {});
        restore_exception_handler();

        try {
            return $application->execute(new CliInput(
                help: false,
                targetRoot: $fixtureRoot,
                outputPath: $outputPath,
                configPath: null,
                mode: $mode,
                strict: $strict,
                features: $features,
            ));
        } finally {
            $currentErrorHandler = set_error_handler(static fn() => false);
            restore_error_handler();

            if ($currentErrorHandler !== $previousErrorHandler) {
                restore_error_handler();
            }

            $currentExceptionHandler = set_exception_handler(static function (Throwable $throwable): void {});
            restore_exception_handler();

            if ($currentExceptionHandler !== $previousExceptionHandler) {
                restore_exception_handler();
            }
        }
    }

    protected function loadIndex(string $outputName): ScipIndexInspector
    {
        return ScipIndexInspector::fromFile(FluxAcceptanceFixture::root() . '/build/' . $outputName);
    }

    protected function loadIndexFromPath(string $path): ScipIndexInspector
    {
        return ScipIndexInspector::fromFile($path);
    }

    protected function makeApplicationWithEnrichers(array $enrichers): IndexApplication
    {
        return new IndexApplication(pipeline: new IndexPipeline(enrichers: $enrichers));
    }

    protected function assertDefinitionAndReference(
        ScipIndexInspector $index,
        string $definitionDocument,
        string $displayName,
        string $referenceDocument,
        int $role = SymbolRole::ReadAccess,
    ): string {
        $symbol = $index->findSymbolByDisplayName($definitionDocument, $displayName);

        self::assertNotNull($symbol, sprintf(
            'Expected definition symbol "%s" in %s.',
            $displayName,
            $definitionDocument,
        ));
        self::assertTrue(
            $index->hasOccurrence($definitionDocument, $symbol, SymbolRole::Definition),
            sprintf('Expected a definition occurrence for "%s" in %s.', $displayName, $definitionDocument),
        );
        self::assertTrue(
            $index->hasOccurrence($referenceDocument, $symbol, $role),
            sprintf('Expected a reference to "%s" in %s.', $displayName, $referenceDocument),
        );

        return $symbol;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Snapshot;

use Bnomei\ScipLaravel\Tests\Support\AcceptanceTestCase;
use Bnomei\ScipLaravel\Tests\Support\FluxAcceptanceFixture;

use function file_get_contents;
use function hash;
use function json_encode;

final class FluxSnapshotTest extends AcceptanceTestCase
{
    public function test_flux_fixture_output_is_deterministic_and_matches_snapshot(): void
    {
        $outputPath = '/tmp/scip-laravel-snapshot-full.scip';

        $firstRun = $this->executeCliIndexToPath($outputPath, mode: 'full');
        $firstBytes = file_get_contents($outputPath);
        $firstIndex = $this->loadIndexFromPath($outputPath);

        $secondRun = $this->executeCliIndexToPath($outputPath, mode: 'full');
        $secondBytes = file_get_contents($outputPath);
        $secondIndex = $this->loadIndexFromPath($outputPath);

        self::assertSame(0, $firstRun->exitCode);
        self::assertSame(0, $secondRun->exitCode);
        self::assertIsString($firstBytes);
        self::assertIsString($secondBytes);
        self::assertSame(hash('sha256', $firstBytes), hash('sha256', $secondBytes));
        self::assertSame($firstIndex->canonicalize(), $secondIndex->canonicalize());
        self::assertJsonStringEqualsJsonFile(
            FluxAcceptanceFixture::snapshotPath('flux-app.full.json'),
            json_encode($firstIndex->canonicalize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}

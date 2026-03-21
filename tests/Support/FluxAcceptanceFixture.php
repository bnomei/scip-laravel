<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function array_map;
use function escapeshellarg;
use function fclose;
use function file_exists;
use function filemtime;
use function flock;
use function fopen;
use function glob;
use function implode;
use function is_resource;
use function proc_close;
use function proc_open;
use function realpath;
use function stream_get_contents;
use function unlink;

final class FluxAcceptanceFixture
{
    private static bool $prepared = false;

    public static function prepare(): string
    {
        if (self::$prepared) {
            return self::root();
        }

        if (file_exists(self::root() . '/.scip-laravel-acceptance-ready') && !self::requiresRefresh()) {
            self::$prepared = true;

            return self::root();
        }

        $lockPath = self::repositoryRoot() . '/tests/.flux-acceptance.lock';
        $lock = fopen($lockPath, 'c+');

        if (!is_resource($lock)) {
            throw new RuntimeException('Could not open Flux acceptance fixture lock file.');
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            throw new RuntimeException('Could not lock Flux acceptance fixture preparation.');
        }

        $command = [
            'bash',
            self::repositoryRoot() . '/fixtures/flux-app/prepare-acceptance.sh',
            self::root(),
        ];
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, self::repositoryRoot());

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Flux acceptance fixture preparation.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            flock($lock, LOCK_UN);
            fclose($lock);

            throw new RuntimeException(
                'Flux acceptance fixture preparation failed: '
                . implode(' ', array_map(escapeshellarg(...), $command))
                . "\nSTDOUT:\n"
                . $stdout
                . "\nSTDERR:\n"
                . $stderr,
            );
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        self::$prepared = true;

        return self::root();
    }

    public static function outputPath(string $fileName): string
    {
        $root = self::prepare();
        foreach (glob($root . '/build/*.scip') ?: [] as $existingPath) {
            if (file_exists($existingPath)) {
                unlink($existingPath);
            }
        }
        $path = $root . '/build/' . $fileName;

        if (file_exists($path)) {
            unlink($path);
        }

        return $path;
    }

    public static function snapshotPath(string $fileName): string
    {
        return self::repositoryRoot() . '/tests/Snapshots/' . $fileName;
    }

    public static function root(): string
    {
        return self::repositoryRoot() . '/fixtures/_worktrees/flux-app';
    }

    public static function repositoryRoot(): string
    {
        return realpath(__DIR__ . '/../..') ?: throw new RuntimeException('Could not resolve repository root.');
    }

    private static function requiresRefresh(): bool
    {
        $readyFile = self::root() . '/.scip-laravel-acceptance-ready';
        $readyTime = filemtime($readyFile);

        if ($readyTime === false) {
            return true;
        }

        foreach ([
            self::repositoryRoot() . '/fixtures/flux-app/acceptance-composer.lock',
            self::repositoryRoot() . '/fixtures/flux-app/materialize-acceptance.php',
            self::repositoryRoot() . '/fixtures/flux-app/prepare-acceptance-composer.php',
            self::repositoryRoot() . '/fixtures/flux-app/prepare-acceptance.sh',
        ] as $path) {
            $mtime = filemtime($path);

            if ($mtime !== false && $mtime > $readyTime) {
                return true;
            }
        }

        $root = self::repositoryRoot() . '/fixtures/flux-app/acceptance-stubs/files';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            $mtime = filemtime($file->getPathname());

            if ($mtime !== false && $mtime > $readyTime) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Application;

use Bnomei\ScipLaravel\Application\RangerSnapshotFactory;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class RangerSnapshotFactoryTest extends TestCase
{
    public function test_collect_returns_empty_snapshot_when_only_non_ranger_features_are_enabled(): void
    {
        $snapshot = (new RangerSnapshotFactory())->collect('/does-not-matter', ['config', 'translations']);

        self::assertSame([], $snapshot->routes);
        self::assertSame([], $snapshot->models);
        self::assertSame([], $snapshot->enums);
        self::assertSame([], $snapshot->broadcastEvents);
        self::assertSame([], $snapshot->broadcastChannels);
        self::assertSame([], $snapshot->environmentVariables);
        self::assertSame([], $snapshot->inertiaSharedData);
    }

    public function test_collects_environment_variables_from_the_dotenv_file(): void
    {
        $root = $this->tempDirectory();

        try {
            file_put_contents($root . '/.env', implode("\n", [
                'APP_NAME=probe-app',
                'APP_URL=http://probe.test',
                'SECRET_TOKEN=topsecret',
                '',
            ]));

            $snapshot = (new RangerSnapshotFactory())->collect($root, ['env']);

            self::assertSame(
                ['APP_NAME', 'APP_URL', 'SECRET_TOKEN'],
                array_map(
                    static fn(object $environmentVariable): string => $environmentVariable->key,
                    $snapshot->environmentVariables,
                ),
            );
            self::assertSame('probe-app', $snapshot->environmentVariables[0]->value);
            self::assertSame('http://probe.test', $snapshot->environmentVariables[1]->value);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/ranger-snapshot-' . bin2hex(random_bytes(8));
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

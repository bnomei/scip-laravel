<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\PhpLiteralMethodCallFinder;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

final class PhpLiteralMethodCallFinderTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_finds_literal_helper_method_calls(): void
    {
        $root = $this->tempDirectory();

        try {
            mkdir($root . '/vendor/composer', 0777, true);
            file_put_contents($root . '/vendor/composer/installed.php', <<<'PHP'
                <?php return ['root' => ['name' => 'probe/app', 'version' => '1.0.0']];
                PHP);
            mkdir($root . '/app', 0777, true);
            file_put_contents($root . '/app/Probe.php', <<<'PHP'
                <?php

                request()->routeIs('dashboard');
                redirect()->route('dashboard');
                app('config')->get('features.registration_enabled');
                PHP);

            $calls = (new PhpLiteralMethodCallFinder())->find($root, [
                'request' => ['methods' => ['routeIs']],
                'redirect' => ['methods' => ['route']],
                'app' => ['methods' => ['get'], 'helper_literal' => 'config'],
            ]);

            $summary = array_map(static fn($call): string => sprintf(
                '%s:%s:%s:%s',
                $call->helper,
                $call->helperLiteral ?? '',
                $call->method,
                $call->literal,
            ), $calls);
            sort($summary);

            self::assertSame(
                [
                    'app:config:get:features.registration_enabled',
                    'redirect::route:dashboard',
                    'request::routeis:dashboard',
                ],
                $summary,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/php-literal-method-call-finder-' . bin2hex(random_bytes(6));

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . '/' . $item;

            if (is_dir($target)) {
                $this->removeDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}

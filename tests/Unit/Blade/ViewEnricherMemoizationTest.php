<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Blade;

use Bnomei\ScipLaravel\Enrichers\Views\ViewEnricher;
use PHPUnit\Framework\TestCase;

final class ViewEnricherMemoizationTest extends TestCase
{
    public function test_resolve_view_path_is_memoized_per_view_name(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'blade-view-');
        self::assertIsString($file);

        file_put_contents($file, 'test');
        $finder = new class($file) {
            public int $calls = 0;

            public function __construct(
                private readonly string $path,
            ) {}

            public function find(string $name): string
            {
                $this->calls++;

                return $this->path;
            }
        };

        try {
            $enricher = new ViewEnricher();
            $method = new \ReflectionMethod($enricher, 'resolveViewPath');
            $method->setAccessible(true);

            $first = $method->invoke($enricher, $finder, 'welcome');
            $second = $method->invoke($enricher, $finder, 'welcome');

            self::assertSame($first, $second);
            self::assertSame(1, $finder->calls);
        } finally {
            @unlink($file);
        }
    }
}

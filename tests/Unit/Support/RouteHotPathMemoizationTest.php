<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Enrichers\Inertia\InertiaEnricher;
use Bnomei\ScipLaravel\Enrichers\Routes\RouteEnricher;
use Bnomei\ScipLaravel\Support\PhpRouteDeclarationFinder;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\RouteDepthExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function random_bytes;
use function sys_get_temp_dir;

final class RouteHotPathMemoizationTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        PhpRouteDeclarationFinder::reset();
        RouteDepthExtractor::reset();
        parent::tearDown();
    }

    public function test_route_declarations_are_cached_per_project_root_until_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $routesPath = $root . '/routes/web.php';
            @mkdir(dirname($routesPath), 0777, true);

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;

                Route::get('/first', fn () => null)->name('first');
                PHP);

            $finder = new PhpRouteDeclarationFinder();

            self::assertCount(1, $finder->find($root));

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;

                Route::get('/first', fn () => null)->name('first');
                Route::get('/second', fn () => null)->name('second');
                PHP);

            self::assertCount(1, $finder->find($root));

            ProjectPhpAnalysisCache::reset();
            PhpRouteDeclarationFinder::reset();

            self::assertCount(2, (new PhpRouteDeclarationFinder())->find($root));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_route_depth_scan_is_cached_per_project_root_until_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $routesPath = $root . '/routes/web.php';
            @mkdir(dirname($routesPath), 0777, true);

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;

                Route::get('/users/{user}', fn () => null)->scopeBindings()->name('users.show');
                PHP);

            $extractor = new RouteDepthExtractor();
            $first = $extractor->routeMetadata($root);

            self::assertCount(1, $first);
            self::assertSame('enabled', $first[0]->scopeBindingsState);

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Illuminate\Support\Facades\Route;

                Route::get('/users/{user}', fn () => null)->withoutScopedBindings()->name('users.show');
                PHP);

            $second = $extractor->routeMetadata($root);

            self::assertCount(1, $second);
            self::assertSame('enabled', $second[0]->scopeBindingsState);

            ProjectPhpAnalysisCache::reset();
            RouteDepthExtractor::reset();

            $refreshed = (new RouteDepthExtractor())->routeMetadata($root);

            self::assertCount(1, $refreshed);
            self::assertSame('disabled', $refreshed[0]->scopeBindingsState);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_route_enricher_reuses_controller_reflection_instances(): void
    {
        $enricher = new RouteEnricher();
        $method = new \ReflectionMethod($enricher, 'reflectionForClass');
        $method->setAccessible(true);

        $first = $method->invoke($enricher, ReflectionClass::class);
        $second = $method->invoke($enricher, ReflectionClass::class);

        self::assertInstanceOf(ReflectionClass::class, $first);
        self::assertSame($first, $second);
    }

    public function test_inertia_component_path_lookup_is_memoized_for_one_run(): void
    {
        $root = $this->tempDirectory();

        try {
            $vuePath = $root . '/resources/js/Pages/Dashboard.vue';
            $tsxPath = $root . '/resources/js/Pages/Dashboard.tsx';
            @mkdir(dirname($vuePath), 0777, true);
            file_put_contents($vuePath, '<template />');

            $enricher = new InertiaEnricher();
            $method = new \ReflectionMethod($enricher, 'componentPaths');
            $method->setAccessible(true);

            $first = $method->invoke($enricher, $root, 'Dashboard');

            file_put_contents($tsxPath, 'export default {};');

            $second = $method->invoke($enricher, $root, 'Dashboard');
            $third = $method->invoke(new InertiaEnricher(), $root, 'Dashboard');

            self::assertSame($first, $second);
            self::assertCount(1, $second);
            self::assertCount(2, $third);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/route-hotpath-' . bin2hex(random_bytes(8));
        @mkdir($root, 0777, true);

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

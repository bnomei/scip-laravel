<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Application;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\RangerSnapshot;
use Bnomei\ScipLaravel\Application\RouteBoundModelScopeInventoryBuilder;
use Bnomei\ScipLaravel\Config\RuntimeConfiguration;
use Bnomei\ScipLaravel\Config\RuntimeMode;
use Bnomei\ScipLaravel\Support\DiagnosticsSink;
use Bnomei\ScipLaravel\Support\RuntimeCacheRegistry;
use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Laravel\Ranger\Components\Model as RangerModel;
use Laravel\Surveyor\Analyzer\Analyzer;
use PHPUnit\Framework\TestCase;
use Scip\Index;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function random_bytes;
use function sys_get_temp_dir;

final class RouteBoundModelScopeInventoryBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeCacheRegistry::reset();
        parent::tearDown();
    }

    public function test_collect_reuses_the_cached_inventory_until_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $routesPath = $root . '/routes/web.php';
            $voltPath = $root . '/resources/views/livewire/accounts/show.blade.php';
            @mkdir(dirname($routesPath), 0777, true);
            @mkdir(dirname($voltPath), 0777, true);

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Livewire\Volt\Volt;

                Volt::route('/accounts/{account}', 'accounts.show')->name('accounts.show');
                PHP);
            file_put_contents($voltPath, <<<'BLADE'
                <?php

                use App\Models\Account;
                use function Livewire\Volt\mount;
                use function Livewire\Volt\state;

                state(['account' => null]);
                mount(fn (Account $account) => null);
                ?>

                <div>account</div>
                BLADE);

            $builder = new RouteBoundModelScopeInventoryBuilder();
            $context = $this->context($root);
            $documentPath = 'resources/views/livewire/accounts/show.blade.php';

            $first = $builder->collect($context);
            self::assertSame('App\\Models\\Account', $first->forDocument($documentPath)['account'] ?? null);

            file_put_contents($routesPath, "<?php\n");
            file_put_contents($voltPath, "<div>changed</div>\n");

            $second = $builder->collect($context);
            self::assertSame('App\\Models\\Account', $second->forDocument($documentPath)['account'] ?? null);

            RuntimeCacheRegistry::reset();

            $third = $builder->collect($context);
            self::assertArrayNotHasKey('account', $third->forDocument($documentPath));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_collect_uses_runtime_router_bindings_without_project_scan(): void
    {
        $root = $this->tempDirectory();

        try {
            $routesPath = $root . '/routes/web.php';
            $voltPath = $root . '/resources/views/livewire/accounts/show.blade.php';
            @mkdir(dirname($routesPath), 0777, true);
            @mkdir(dirname($voltPath), 0777, true);

            file_put_contents($routesPath, <<<'PHP'
                <?php

                use Livewire\Volt\Volt;

                Volt::route('/accounts/{account}', 'accounts.show')->name('accounts.show');
                PHP);
            file_put_contents($voltPath, <<<'BLADE'
                <?php

                use function Livewire\Volt\mount;
                use function Livewire\Volt\state;

                state(['account' => null]);
                mount(fn ($account) => null);
                ?>

                <div>account</div>
                BLADE);

            $router = new Router(new Dispatcher(new Container()), new Container());
            $router->model('account', 'App\Models\Account');

            $application = new class($router) {
                public function __construct(
                    private readonly Router $router,
                ) {}

                public function make(string $abstract): mixed
                {
                    return $abstract === Router::class
                        ? $this->router
                        : throw new \RuntimeException('Unsupported binding: ' . $abstract);
                }
            };

            $builder = new RouteBoundModelScopeInventoryBuilder();
            $context = $this->context($root, $application);
            $documentPath = 'resources/views/livewire/accounts/show.blade.php';

            $inventory = $builder->collect($context);

            self::assertSame('App\\Models\\Account', $inventory->forDocument($documentPath)['account'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function context(string $projectRoot, mixed $application = null): LaravelContext
    {
        $config = new RuntimeConfiguration(
            configPath: '',
            configLoaded: false,
            outputPath: '/tmp/output.scip',
            mode: RuntimeMode::Full,
            strict: false,
            features: ['models'],
        );
        $analyzer = new class extends Analyzer {
            public function __construct() {}

            public function analyzeClass(string $className)
            {
                return new class {
                    public function result(): mixed
                    {
                        return null;
                    }
                };
            }
        };

        return new LaravelContext(
            projectRoot: $projectRoot,
            config: $config,
            mode: RuntimeMode::Full,
            application: $application ?? new \stdClass(),
            consoleKernel: null,
            analyzer: $analyzer,
            surveyor: new SurveyorMetadataRepository($analyzer),
            rangerSnapshot: new RangerSnapshot(
                routes: [],
                models: [new RangerModel('App\\Models\\Account')],
                enums: [],
                broadcastEvents: [],
                broadcastChannels: [],
                environmentVariables: [],
                inertiaSharedData: [],
                inertiaComponents: [],
            ),
            baselineIndex: new Index(),
            diagnostics: new DiagnosticsSink(),
            enabledFeatures: ['models'],
        );
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/route-bound-scope-cache-' . bin2hex(random_bytes(8));
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

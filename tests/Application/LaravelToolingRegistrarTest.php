<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Application;

use Bnomei\ScipLaravel\Application\LaravelBootstrapper;
use Bnomei\ScipLaravel\Application\LaravelToolingRegistrar;
use Bnomei\ScipLaravel\Application\ProjectRouteAnalysisScope;
use Bnomei\ScipLaravel\Application\ProjectScopedFormRequests;
use Bnomei\ScipLaravel\Application\ProjectScopedResponse;
use Bnomei\ScipLaravel\Tests\Support\FluxAcceptanceFixture;
use Laravel\Ranger\Collectors\FormRequests;
use Laravel\Ranger\Collectors\Response;
use Laravel\Ranger\Collectors\Routes;
use Laravel\Surveyor\Analyzer\AnalyzedCache;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

use function hash;
use function realpath;
use function restore_error_handler;
use function restore_exception_handler;
use function set_error_handler;
use function set_exception_handler;
use function sys_get_temp_dir;

final class LaravelToolingRegistrarTest extends TestCase
{
    protected function tearDown(): void
    {
        AnalyzedCache::clear();
        AnalyzedCache::disable();

        parent::tearDown();
    }

    public function test_register_binds_project_scoped_route_analysis_services(): void
    {
        $root = FluxAcceptanceFixture::prepare();
        $application = $this->bootstrapApplication($root);

        (new LaravelToolingRegistrar())->register($application, $root);

        self::assertInstanceOf(ProjectScopedFormRequests::class, $application->make(FormRequests::class));
        self::assertInstanceOf(ProjectScopedResponse::class, $application->make(Response::class));

        $routes = $application->make(Routes::class);
        $formRequests = $this->readProperty($routes, 'formRequestCollector');
        $response = $this->readProperty($routes, 'responseCollector');

        self::assertInstanceOf(ProjectScopedFormRequests::class, $formRequests);
        self::assertInstanceOf(ProjectScopedResponse::class, $response);
    }

    public function test_register_enables_project_scoped_surveyor_cache(): void
    {
        $root = FluxAcceptanceFixture::prepare();
        $application = $this->bootstrapApplication($root);

        (new LaravelToolingRegistrar())->register($application, $root);

        $cacheDirectory = $this->readStaticProperty(AnalyzedCache::class, 'cacheDirectory');
        $persistToDisk = $this->readStaticProperty(AnalyzedCache::class, 'persistToDisk');

        self::assertTrue($persistToDisk);
        self::assertIsString($cacheDirectory);
        self::assertStringContainsString(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scip-laravel', $cacheDirectory);
        self::assertStringContainsString(hash('sha256', realpath($root) ?: $root), $cacheDirectory);
    }

    public function test_project_route_analysis_scope_only_allows_project_controllers(): void
    {
        $root = FluxAcceptanceFixture::prepare();
        $this->bootstrapApplication($root);
        $scope = new ProjectRouteAnalysisScope($root);

        self::assertTrue($scope->shouldAnalyze([
            'uses' => 'App\Http\Controllers\AcceptanceValidatedRouteController@store',
        ]));
        self::assertFalse($scope->shouldAnalyze([
            'uses' => 'Livewire\Mechanisms\FrontendAssets\FrontendAssets@returnJavaScriptAsFile',
        ]));
        self::assertFalse($scope->shouldAnalyze([
            'uses' => 'Illuminate\Routing\RedirectController@__invoke',
        ]));
        self::assertFalse($scope->shouldAnalyze([
            'uses' => null,
        ]));
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function bootstrapApplication(string $root): object
    {
        $previousErrorHandler = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $previousExceptionHandler = set_exception_handler(static function (Throwable $throwable): void {});
        restore_exception_handler();

        try {
            return (new LaravelBootstrapper())->bootstrap($root)->application;
        } finally {
            $currentErrorHandler = set_error_handler(static fn(): bool => false);
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

    private function readStaticProperty(string $className, string $property): mixed
    {
        $reflection = new ReflectionProperty($className, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue();
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Laravel\Ranger\Collectors\FormRequests;
use Laravel\Ranger\Collectors\Response;
use Laravel\Ranger\Collectors\Routes;
use Laravel\Ranger\RangerServiceProvider;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\SurveyorServiceProvider;

use function class_exists;
use function method_exists;

final class LaravelToolingRegistrar
{
    public function __construct(
        private readonly SurveyorCacheConfigurator $surveyorCacheConfigurator = new SurveyorCacheConfigurator(),
    ) {}

    public function register(object $application, string $projectRoot): void
    {
        if (!method_exists($application, 'register')) {
            return;
        }

        if (class_exists(SurveyorServiceProvider::class)) {
            $application->register(SurveyorServiceProvider::class);
            $this->surveyorCacheConfigurator->configure($projectRoot);
        }

        if (class_exists(RangerServiceProvider::class)) {
            $application->register(RangerServiceProvider::class);
        }

        // These overrides only exist inside the scip-laravel indexing process.
        if (
            !method_exists($application, 'singleton')
            || !class_exists(FormRequests::class)
            || !class_exists(Response::class)
            || !class_exists(Routes::class)
            || !class_exists(Analyzer::class)
        ) {
            return;
        }

        if (method_exists($application, 'forgetInstance')) {
            $application->forgetInstance(ProjectRouteAnalysisScope::class);
            $application->forgetInstance(FormRequests::class);
            $application->forgetInstance(Response::class);
            $application->forgetInstance(Routes::class);
        }

        $application->singleton(
            ProjectRouteAnalysisScope::class,
            static fn(): ProjectRouteAnalysisScope => new ProjectRouteAnalysisScope($projectRoot),
        );
        $application->singleton(
            FormRequests::class,
            static fn($app): ProjectScopedFormRequests => new ProjectScopedFormRequests(
                $app->make(Analyzer::class),
                $app->make(ProjectRouteAnalysisScope::class),
            ),
        );
        $application->singleton(
            Response::class,
            static fn($app): ProjectScopedResponse => new ProjectScopedResponse(
                $app->make(Analyzer::class),
                $app->make(ProjectRouteAnalysisScope::class),
            ),
        );
    }
}

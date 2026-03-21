<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AcceptanceGreeter;
use App\Enums\AcceptanceStatus;
use App\Events\AcceptanceAsyncEvent;
use App\Listeners\AcceptanceAsyncListener;
use App\Models\AcceptanceUser;
use App\Policies\AcceptanceUserPolicy;
use App\Services\AcceptanceCacheRepository;
use App\Services\AcceptanceConsumer;
use App\Services\AcceptanceGreeterService;
use Illuminate\Support\Facades\Event;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AcceptanceGraphServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        Repository::class => AcceptanceCacheRepository::class,
    ];

    public function register(): void
    {
        $this->app->singleton(AcceptanceGreeter::class, AcceptanceGreeterService::class);

        $this->app->when(AcceptanceConsumer::class)
            ->needs(AcceptanceGreeter::class)
            ->give(AcceptanceGreeterService::class);
    }

    public function boot(): void
    {
        Route::model('account', AcceptanceUser::class);
        Route::bind('statusBound', static fn (string $value): AcceptanceStatus => AcceptanceStatus::from($value));
        Gate::policy(AcceptanceUser::class, AcceptanceUserPolicy::class);
        Event::listen(AcceptanceAsyncEvent::class, AcceptanceAsyncListener::class);
    }
}

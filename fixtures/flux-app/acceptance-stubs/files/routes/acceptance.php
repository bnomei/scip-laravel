<?php

declare(strict_types=1);

use App\Http\Controllers\AcceptanceArticleController;
use App\Http\Controllers\AcceptanceAuthorizedController;
use App\Http\Controllers\AcceptanceValidatedRouteController;
use App\Enums\AcceptanceStatus;
use App\Livewire\AcceptanceExplicitRouteBound;
use App\Http\Controllers\AcceptanceInertiaController;
use App\Http\Controllers\AcceptanceProfileController;
use App\Livewire\AcceptanceRealtime;
use App\Http\Controllers\AcceptanceViewController;
use App\Http\Middleware\EnsureAcceptanceToken;
use App\Livewire\AcceptanceRouteBound;
use App\Livewire\AcceptanceUnsupportedRouteBound;
use App\Livewire\AcceptanceValidation;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/acceptance/view', AcceptanceViewController::class)
    ->name('acceptance.route-probe');

Route::view('/dashboard', 'acceptance.dashboard-link')
    ->name('dashboard');

Route::view('/acceptance/static-view', 'acceptance.route-show')
    ->name('acceptance.static-view');

Route::view('/acceptance/authorization', 'acceptance.authorization')
    ->name('acceptance.authorization');

Route::view('/acceptance/navigation', 'acceptance.navigation')
    ->name('acceptance.navigation');

Route::view('/acceptance/layout-child', 'acceptance.layout-child')
    ->name('acceptance.layout-child');

Route::redirect('/acceptance/redirect', '/acceptance/view')
    ->name('acceptance.redirect');

Route::name('acceptance.')
    ->prefix('/acceptance/grouped')
    ->group(function (): void {
        Route::resource('articles', AcceptanceArticleController::class)
            ->only(['index', 'show']);

        Route::singleton('profile', AcceptanceProfileController::class)
            ->only(['show', 'edit']);
    });

Route::get('/acceptance/inertia', [AcceptanceInertiaController::class, 'show'])
    ->name('acceptance.inertia');

Route::get('/acceptance/inertia/secondary', [AcceptanceInertiaController::class, 'secondary'])
    ->name('acceptance.inertia.secondary');

Route::post('/acceptance/validated/{status}', [AcceptanceValidatedRouteController::class, 'store'])
    ->name('acceptance.validated');

Route::get('/acceptance/optional/{slug?}', static fn (?string $slug = null): string => $slug ?? 'fallback')
    ->defaults('slug', 'fallback')
    ->name('acceptance.optional');

Route::post('/acceptance/authorized', [AcceptanceAuthorizedController::class, 'store'])
    ->middleware(['auth', EnsureAcceptanceToken::class])
    ->can('manage-acceptance', \App\Models\AcceptanceUser::class)
    ->name('acceptance.authorized');

Route::get('/acceptance/livewire/{user}', AcceptanceRouteBound::class)
    ->name('acceptance.livewire.route-bound');

Route::get('/acceptance/livewire/unsupported/{user:email}', AcceptanceUnsupportedRouteBound::class)
    ->name('acceptance.livewire.route-bound.unsupported');

Route::get('/acceptance/livewire/validation', AcceptanceValidation::class)
    ->name('acceptance.livewire.validation');

Route::get('/acceptance/livewire/realtime', AcceptanceRealtime::class)
    ->name('acceptance.livewire.realtime');

Route::get('/acceptance/livewire/explicit/{account}', AcceptanceExplicitRouteBound::class)
    ->scopeBindings()
    ->can('manage-acceptance', 'account')
    ->missing(static fn () => to_route('acceptance.route-probe'))
    ->name('acceptance.livewire.explicit');

Route::get('/acceptance/enum/{statusBound}', static fn (AcceptanceStatus $statusBound): string => $statusBound->value)
    ->name('acceptance.enum-bound');

Route::get('/acceptance/placeholder/{section?}', static fn (?string $section = 'overview'): string => $section ?? 'overview')
    ->name('acceptance.placeholder');

Volt::route('/acceptance/volt', 'acceptance-model')
    ->name('acceptance.volt');

Volt::route('/acceptance/volt/{user}', 'acceptance-model')
    ->name('acceptance.volt.route-bound');

if (app()->environment('local')) {
    Route::get('/acceptance/full-only', static fn () => 'full')
        ->name('acceptance.full-only');
}

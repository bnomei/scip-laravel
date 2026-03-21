<?php

declare(strict_types=1);

use App\Jobs\AcceptanceSendDigestJob;
use App\Support\AcceptanceScheduleProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('acceptance:closure', function (): void {
})->purpose('Acceptance closure command');

Schedule::command('acceptance:report')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new AcceptanceSendDigestJob())
    ->everyFiveMinutes()
    ->onOneServer();

Schedule::call([AcceptanceScheduleProbe::class, 'run'])
    ->dailyAt('13:00');

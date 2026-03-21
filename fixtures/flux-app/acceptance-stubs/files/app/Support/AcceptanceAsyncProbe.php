<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\AcceptanceAsyncEvent;
use App\Jobs\AcceptanceSendDigestJob;
use App\Notifications\AcceptanceDigestNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

final class AcceptanceAsyncProbe
{
    public function dispatchAll(): void
    {
        dispatch(new AcceptanceSendDigestJob());
        AcceptanceSendDigestJob::dispatch();
        Bus::batch([new AcceptanceSendDigestJob()])->dispatch();

        event(new AcceptanceAsyncEvent());
        Event::dispatch(new AcceptanceAsyncEvent());

        Notification::send([], new AcceptanceDigestNotification());
        Notification::route('mail', 'acceptance@example.test')
            ->notify(new AcceptanceDigestNotification());
    }
}

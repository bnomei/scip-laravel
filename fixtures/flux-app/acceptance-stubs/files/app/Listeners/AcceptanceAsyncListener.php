<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class AcceptanceAsyncListener implements ShouldQueue
{
    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('acceptance-listener')];
    }

    public function handle(object $event): void
    {
    }
}

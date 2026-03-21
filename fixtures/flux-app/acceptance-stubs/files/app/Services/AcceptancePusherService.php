<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AcceptancePusher;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class AcceptancePusherService implements AcceptancePusher
{
    public function push(string $payload): string
    {
        return $payload;
    }
}

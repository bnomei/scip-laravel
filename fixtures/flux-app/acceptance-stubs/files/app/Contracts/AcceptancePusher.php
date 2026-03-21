<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\AcceptancePusherService;
use Illuminate\Container\Attributes\Bind;

#[Bind(AcceptancePusherService::class, environments: ['local', 'testing'])]
interface AcceptancePusher
{
    public function push(string $payload): string;
}

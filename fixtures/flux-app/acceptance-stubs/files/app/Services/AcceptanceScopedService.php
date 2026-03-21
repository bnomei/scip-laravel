<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Container\Attributes\Scoped;

#[Scoped]
final class AcceptanceScopedService
{
    public function id(): string
    {
        return 'scoped';
    }
}

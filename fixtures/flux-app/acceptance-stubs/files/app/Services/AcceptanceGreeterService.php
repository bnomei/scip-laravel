<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AcceptanceGreeter;

final class AcceptanceGreeterService implements AcceptanceGreeter
{
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}

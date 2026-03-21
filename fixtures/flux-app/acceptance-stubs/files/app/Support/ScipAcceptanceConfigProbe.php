<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

final class ScipAcceptanceConfigProbe
{
    public function read(): array
    {
        return [
            config('scip_laravel_acceptance.primary.label'),
            Config::get('scip_laravel_acceptance.primary.label'),
        ];
    }
}

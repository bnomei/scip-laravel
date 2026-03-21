<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

final class AcceptanceConfigProbe
{
    public function read(): array
    {
        return [
            config('scip-acceptance.ui.label'),
            Config::get('scip-acceptance.ui.label'),
            app('config')->get('scip-acceptance.ui.label'),
        ];
    }
}

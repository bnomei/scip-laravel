<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\View;

final class ScipAcceptanceViewProbe
{
    public function make(): array
    {
        return [
            view('acceptance.route-show'),
            View::make('acceptance.component-tags'),
        ];
    }
}

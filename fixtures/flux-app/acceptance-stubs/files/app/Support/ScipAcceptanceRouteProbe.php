<?php

declare(strict_types=1);

namespace App\Support;

final class ScipAcceptanceRouteProbe
{
    public function links(): array
    {
        return [
            route('scip.acceptance.show'),
            route('scip.acceptance.volt'),
            route('scip.acceptance.full-only'),
            to_route('scip.acceptance.show'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

final class AcceptanceEnvProbe
{
    public function read(): array
    {
        return [
            env('SCIP_ACCEPTANCE_TOKEN'),
            env('SCIP_ACCEPTANCE_TOKEN', false),
        ];
    }
}

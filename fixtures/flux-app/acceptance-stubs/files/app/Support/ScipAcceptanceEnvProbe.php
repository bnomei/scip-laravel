<?php

declare(strict_types=1);

namespace App\Support;

final class ScipAcceptanceEnvProbe
{
    public function read(): ?string
    {
        return env('SCIP_ACCEPTANCE_FLAG');
    }
}

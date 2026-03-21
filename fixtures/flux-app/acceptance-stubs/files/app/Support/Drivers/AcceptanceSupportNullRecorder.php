<?php

declare(strict_types=1);

namespace App\Support\Drivers;

use App\Support\Contracts\AcceptanceSupportRecorder;

final class AcceptanceSupportNullRecorder implements AcceptanceSupportRecorder
{
    public function capture(string $event, array $payload = []): void
    {
    }
}

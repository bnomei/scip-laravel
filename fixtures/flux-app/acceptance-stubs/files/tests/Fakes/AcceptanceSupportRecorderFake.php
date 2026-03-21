<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Support\Contracts\AcceptanceSupportRecorder;

final class AcceptanceSupportRecorderFake implements AcceptanceSupportRecorder
{
    public function capture(string $event, array $payload = []): void
    {
    }
}

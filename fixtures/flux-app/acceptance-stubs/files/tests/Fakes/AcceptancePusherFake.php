<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\AcceptancePusher;

final class AcceptancePusherFake implements AcceptancePusher
{
    public function push(string $payload): string
    {
        return 'fake:' . $payload;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Contracts;

interface AcceptanceSupportRecorder
{
    public function capture(string $event, array $payload = []): void;
}

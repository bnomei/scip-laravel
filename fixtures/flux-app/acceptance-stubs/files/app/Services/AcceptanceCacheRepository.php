<?php

declare(strict_types=1);

namespace App\Services;

final class AcceptanceCacheRepository
{
    public function get(string $key): mixed
    {
        return $key;
    }
}

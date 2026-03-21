<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AcceptanceGreeter;
use Illuminate\Container\Attributes\Cache;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository;

final class AcceptanceConsumer
{
    public function __construct(
        public readonly AcceptanceGreeter $greeter,
        #[Config('app.name')]
        public readonly string $appName,
        #[Cache('redis')]
        public readonly Repository $cacheStore,
    ) {
    }
}

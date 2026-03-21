<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class RangerSnapshot
{
    /**
     * @param list<object> $routes
     * @param list<object> $models
     * @param list<object> $enums
     * @param list<object> $broadcastEvents
     * @param list<object> $broadcastChannels
     * @param list<object> $environmentVariables
     * @param list<object> $inertiaSharedData
     * @param array<string, object> $inertiaComponents
     */
    public function __construct(
        public array $routes,
        public array $models,
        public array $enums,
        public array $broadcastEvents,
        public array $broadcastChannels,
        public array $environmentVariables,
        public array $inertiaSharedData,
        public array $inertiaComponents,
    ) {}
}

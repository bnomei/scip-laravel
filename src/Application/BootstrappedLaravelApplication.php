<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

final readonly class BootstrappedLaravelApplication
{
    public function __construct(
        public object $application,
        public ?object $consoleKernel,
    ) {}
}

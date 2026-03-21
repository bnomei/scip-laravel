<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Config\RuntimeConfiguration;

final readonly class PreparedRun
{
    public function __construct(
        public string $targetRoot,
        public RuntimeConfiguration $config,
        public object $application,
        public ?object $consoleKernel,
    ) {}
}

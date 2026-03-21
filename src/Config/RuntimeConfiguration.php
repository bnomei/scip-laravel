<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Config;

final readonly class RuntimeConfiguration
{
    /**
     * @param list<non-empty-string> $features
     */
    public function __construct(
        public string $configPath,
        public bool $configLoaded,
        public string $outputPath,
        public RuntimeMode $mode,
        public bool $strict,
        public array $features,
    ) {}
}

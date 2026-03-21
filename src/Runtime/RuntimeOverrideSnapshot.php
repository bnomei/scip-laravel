<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Runtime;

final class RuntimeOverrideSnapshot
{
    /**
     * @param array<string, string|null> $previous
     */
    public function __construct(
        private readonly array $previous,
    ) {}

    public function restore(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

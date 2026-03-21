<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use function array_keys;

final class UniqueSymbolRegistry
{
    /**
     * @var array<string, true>
     */
    private array $symbols = [];

    public function remember(?string $symbol): ?string
    {
        if ($symbol === null || $symbol === '' || isset($this->symbols[$symbol])) {
            return null;
        }

        $this->symbols[$symbol] = true;

        return $symbol;
    }

    public function contains(string $symbol): bool
    {
        return isset($this->symbols[$symbol]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->symbols);
    }
}

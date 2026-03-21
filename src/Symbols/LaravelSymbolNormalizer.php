<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Symbols;

use function preg_match;
use function preg_replace;
use function rawurlencode;
use function str_starts_with;
use function trim;

final class LaravelSymbolNormalizer
{
    public function route(?string $name): ?string
    {
        return $this->build('routes', $name);
    }

    public function view(?string $name): ?string
    {
        return $this->build('views', $name);
    }

    public function inertia(?string $name): ?string
    {
        return $this->build('inertia', $name);
    }

    public function broadcastChannel(?string $name): ?string
    {
        return $this->build('broadcast-channel', $name);
    }

    public function config(?string $key): ?string
    {
        return $this->build('config', $key);
    }

    public function translation(?string $key): ?string
    {
        return $this->build('trans', $key);
    }

    public function modelAttribute(?string $className, ?string $attributeName): ?string
    {
        if ($className === null || $attributeName === null) {
            return null;
        }

        return $this->build('model-attribute', $className . '#' . $attributeName);
    }

    public function env(?string $name): ?string
    {
        return $this->build('env', $name);
    }

    public function isSynthetic(string $symbol): bool
    {
        return str_starts_with($symbol, 'laravel/');
    }

    private function build(string $domain, ?string $value): ?string
    {
        $normalized = $this->normalizeLiteral($value);

        if ($normalized === null) {
            return null;
        }

        return "laravel/{$domain}/{$normalized}.";
    }

    private function normalizeLiteral(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return rawurlencode($normalized);
    }
}

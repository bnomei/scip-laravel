<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use function ltrim;
use function str_contains;
use function str_replace;
use function str_starts_with;

final class LivewirePhpEntrypoints
{
    /**
     * @return array<string, list<string>>
     */
    public static function staticMethods(): array
    {
        return [
            'Livewire\\Livewire' => ['mount', 'new', 'test'],
            'Livewire' => ['mount', 'new', 'test'],
            'Livewire\\Volt\\Volt' => ['test'],
            'Volt' => ['test'],
        ];
    }

    public static function viewName(PhpLiteralCall $call): ?string
    {
        return match ($call->callee) {
            'livewire\\livewire::mount',
            'livewire\\livewire::new',
            'livewire\\livewire::test',
            'livewire::mount',
            'livewire::new',
            'livewire::test',
            'livewire\\volt\\volt::test',
            'volt::test',
                => self::livewireViewName($call->literal),
            default => null,
        };
    }

    private static function livewireViewName(string $literal): string
    {
        $literal = ltrim($literal, '\\');
        $literal = str_replace(['/', '\\'], '.', $literal);

        if (str_contains($literal, '::')) {
            return str_replace('::', '.', $literal);
        }

        return str_starts_with($literal, 'livewire.') ? $literal : 'livewire.' . $literal;
    }
}

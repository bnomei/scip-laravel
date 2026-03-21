<?php

declare(strict_types=1);

namespace App\Support;

use Livewire\Livewire;
use Livewire\Volt\Volt;

final class AcceptanceLivewireProbe
{
    public function boot(): void
    {
        Livewire::test('posts');
        Volt::test('acceptance-model');
    }
}

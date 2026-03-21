<?php

declare(strict_types=1);

namespace App\Support;

use Livewire\Livewire;
use Livewire\Volt\Volt;

final class ScipAcceptanceLivewireEntrypointProbe
{
    public function boot(): void
    {
        Livewire::test('acceptance.banner-panel');
        Livewire::test('pages::settings.profile');
        Livewire::test('posts');
        Livewire::mount('team.profile');
        Livewire::new('team.member');
        Volt::test('team.member');
    }
}

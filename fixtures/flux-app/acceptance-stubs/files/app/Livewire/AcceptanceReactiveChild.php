<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

final class AcceptanceReactiveChild extends Component
{
    #[Reactive]
    public string $title = '';

    #[Reactive]
    public bool $open = false;

    public function render(): View
    {
        return view('livewire.acceptance-reactive-child');
    }
}

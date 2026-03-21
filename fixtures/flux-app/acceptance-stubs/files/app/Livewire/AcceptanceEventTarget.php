<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class AcceptanceEventTarget extends Component
{
    #[On('saved')]
    public function refreshAfterSave(): void
    {
    }

    public function render(): View
    {
        return view('livewire.acceptance-event-target');
    }
}

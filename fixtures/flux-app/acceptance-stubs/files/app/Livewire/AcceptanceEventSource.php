<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AcceptanceEventSource extends Component
{
    public function emitSaved(): void
    {
        $this->dispatch('saved');
    }

    public function emitSavedSelf(): void
    {
        $this->dispatchSelf('saved');
    }

    public function emitSavedTo(): void
    {
        $this->dispatchTo('acceptance-event-target', 'saved');
    }

    public function render(): View
    {
        return view('livewire.acceptance-event-source');
    }
}

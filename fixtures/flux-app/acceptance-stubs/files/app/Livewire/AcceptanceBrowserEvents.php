<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

final class AcceptanceBrowserEvents extends Component
{
    public function emitSaved(): void
    {
        $this->dispatch('saved');
    }

    public function save(): void
    {
    }

    public function render()
    {
        return view('livewire.acceptance-browser-events');
    }
}

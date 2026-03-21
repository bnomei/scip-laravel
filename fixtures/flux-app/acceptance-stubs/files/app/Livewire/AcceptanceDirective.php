<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AcceptanceDirective extends Component
{
    public string $title = '';

    public bool $open = false;

    public function save(): void
    {
    }

    public function load(): void
    {
    }

    public function reorder(): void
    {
    }

    public function render(): View
    {
        return view('livewire.acceptance-directive');
    }
}

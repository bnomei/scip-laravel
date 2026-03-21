<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

final class AcceptanceChildInput extends Component
{
    #[Modelable]
    public string $value = '';

    public function render(): View
    {
        return view('livewire.acceptance-child-input');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AcceptanceExplicitRouteBound extends Component
{
    public mixed $account = null;

    public function render(): View
    {
        return view('livewire.acceptance-explicit-route-bound');
    }
}

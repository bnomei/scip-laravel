<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AcceptanceUser;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AcceptanceRouteBound extends Component
{
    public AcceptanceUser $user;

    public function render(): View
    {
        return view('livewire.acceptance-route-bound');
    }
}

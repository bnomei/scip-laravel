<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AcceptanceUser;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class AcceptanceUnsupportedRouteBound extends Component
{
    public AcceptanceUser $user;

    public function render(): View
    {
        return view('livewire.acceptance-unsupported-route-bound');
    }
}

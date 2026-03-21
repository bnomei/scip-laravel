<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AcceptanceRealtime extends Component
{
    use WithFileUploads;

    public mixed $photo = null;

    public function refresh(): void
    {
        $this->stream(to: 'feed-count', content: 'Updated');
        $this->stream(content: 'Ready')->to(ref: 'feed-panel');
    }

    public function render(): View
    {
        return view('livewire.acceptance-realtime');
    }
}

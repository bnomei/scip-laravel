<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Acceptance Attributes')]
final class AcceptanceAttributes extends Component
{
    #[Locked]
    public int $postId = 1;

    #[Session]
    public string $filter = '';

    #[Url]
    public string $query = '';

    #[Validate('required|min:3')]
    public string $title = '';

    #[Computed]
    public function total(): int
    {
        return 1;
    }

    #[On('saved')]
    public function refreshAfterSave(): void
    {
    }

    public function render(): View
    {
        return view('livewire.acceptance-attributes');
    }
}

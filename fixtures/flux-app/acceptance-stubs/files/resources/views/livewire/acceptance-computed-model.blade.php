<?php

use App\Models\AcceptanceUser;
use Livewire\Volt\Component;

new class extends Component
{
    public function course(): AcceptanceUser
    {
        return new AcceptanceUser();
    }
};
?>

<div>{{ $this->course->declaredSummary() }}</div>

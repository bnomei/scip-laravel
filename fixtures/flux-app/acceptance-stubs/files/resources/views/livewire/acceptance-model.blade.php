<?php

use App\Models\AcceptanceUser;
use function Livewire\Volt\mount;

mount(function (AcceptanceUser $user): void {
});
?>

<div>{{ $user->profiles }}</div>

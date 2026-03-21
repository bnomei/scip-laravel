<?php

use function Livewire\Volt\{computed, layout, state, title};

state(['query' => 'hello']);
$summary = computed(fn () => strtoupper($this->query));
layout('components.layouts.app');
title('Acceptance Volt Attributes');

?>

<div>
    {{ $this->summary }}
</div>

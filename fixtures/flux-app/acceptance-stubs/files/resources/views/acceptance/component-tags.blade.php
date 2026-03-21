@livewire('posts')
@lang('scip-acceptance.messages.welcome')

<livewire:team.profile :$team />
<x-layouts.app>
    <x-acceptance.banner type="warning" message="Heads up" class="rounded" />
    <flux:icon.github />
    <flux:heading />
</x-layouts.app>

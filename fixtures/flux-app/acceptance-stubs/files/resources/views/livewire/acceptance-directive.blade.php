<div>
    <input wire:model="title" />
    <input wire:model.live.blur="title" />
    <input wire:bind:value="title" />

    <span wire:text="title"></span>
    <div wire:show="open"></div>
    <div wire:init="load"></div>
    <ul wire:sort="reorder"></ul>
    <div wire:loading.class="opacity-50" wire:target="save"></div>
    <div wire:ignore></div>
    <div wire:replace></div>
    <div wire:offline></div>

    <button wire:click="save" type="button">Save</button>

    <form wire:submit="save">
        <button type="submit">Submit</button>
    </form>

    <livewire:acceptance-child-input wire:model="title" />
    <livewire:acceptance-reactive-child :title="$title" :$open />
    <livewire:acceptance-reactive-child :title="$title.'-dynamic'" />

    <button wire:click="save(1)" type="button">Skip parameterized action</button>
    <button wire:click="$toggle('open')" type="button">Skip helper action</button>
</div>

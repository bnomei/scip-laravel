<div
    wire:saved.window="save"
    x-on:saved.window="open = true"
    x-on:click="$dispatch('saved')"
>
    <button wire:click="emitSaved" type="button">Emit</button>
    <button type="button" x-on:click="$wire.$dispatch('saved')">Dispatch</button>
    <button type="button" x-on:click="$wire.$dispatchSelf('saved')">Dispatch Self</button>
    <button type="button" x-on:click="$wire.$dispatchTo('acceptance-browser-events', 'saved')">Dispatch To</button>
    <button type="button" wire:saved="save(1)">Skip parameterized</button>
</div>

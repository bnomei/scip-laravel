<div>
    <input wire:model="title" />
    <input wire:model="form.title" />

    @error('title')
        <span>{{ $message }}</span>
    @enderror

    @error('form.title')
        <span>{{ $message }}</span>
    @enderror

    @error('orphan')
        <span>{{ $message }}</span>
    @enderror

    @error($dynamicErrorKey)
        <span>{{ $message }}</span>
    @enderror
</div>

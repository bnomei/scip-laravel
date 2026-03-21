@php($dynamicSlot = 'ignored')

<x-acceptance.card title="Blade locals">
    <x-slot:footer>
        Footer slot
    </x-slot:footer>

    <x-slot name="actions">
        Actions slot
    </x-slot>

    <x-slot :name="$dynamicSlot">
        Dynamic slot should be ignored
    </x-slot>

    Body
</x-acceptance.card>

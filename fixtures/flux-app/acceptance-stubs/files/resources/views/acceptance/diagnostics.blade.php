@php($dynamicPartial = 'acceptance.partials.banner')

@include($dynamicPartial)

<x-dynamic-component :component="$dynamicPartial" />

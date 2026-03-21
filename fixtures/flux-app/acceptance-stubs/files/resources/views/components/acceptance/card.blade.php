@props([
    'title' => 'Untitled',
])

@aware([
    'accent' => 'zinc',
])

<section data-title="{{ $title }}" data-accent="{{ $accent }}">
    <h1>{{ $title }}</h1>

    <div>{{ $slot }}</div>

    @isset($footer)
        <footer>{{ $footer }}</footer>
    @endisset
</section>

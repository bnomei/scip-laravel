<nav>
    <a href="{{ route('acceptance.route-probe') }}" wire:navigate.hover>
        Route probe
    </a>

    @if (request()->routeIs('acceptance.navigation'))
        <span>Navigation active</span>
    @endif

    <a href="/acceptance/static-view" wire:navigate wire:current="exact">
        Static view
    </a>

    <a href="{{ redirect()->route('acceptance.placeholder')->getTargetUrl() }}">
        Placeholder redirect
    </a>
</nav>

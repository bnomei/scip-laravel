<?php

declare(strict_types=1);

namespace App\Support;

final class AcceptanceRouteProbe
{
    public function links(): array
    {
        return [
            route('dashboard'),
            to_route('dashboard'),
            request()->routeIs('dashboard'),
            redirect()->route('dashboard'),
            route('acceptance.route-probe'),
            route('acceptance.navigation'),
            route('acceptance.layout-child'),
            route('acceptance.static-view'),
            route('acceptance.redirect'),
            route('acceptance.articles.index'),
            route('acceptance.profile.show'),
            route('acceptance.authorized'),
            route('acceptance.inertia'),
            route('acceptance.validated', ['status' => 'draft']),
            route('acceptance.optional'),
            route('acceptance.livewire.realtime'),
            route('acceptance.livewire.explicit'),
            route('acceptance.enum-bound', ['statusBound' => 'draft']),
            route('acceptance.volt'),
            route('acceptance.full-only'),
            to_route('acceptance.placeholder'),
            request()->routeIs('acceptance.navigation'),
            redirect()->route('acceptance.authorization'),
        ];
    }
}

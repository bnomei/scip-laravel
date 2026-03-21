<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;

final class AcceptanceTranslationProbe
{
    public function read(): array
    {
        return [
            __('scip-acceptance.messages.welcome'),
            trans('scip-acceptance.messages.welcome'),
            __('pages.dashboard'),
            trans('pages.dashboard'),
            __('Acceptance JSON Key'),
            trans('Settings'),
            Lang::get('Acceptance JSON Key'),
            Lang::get('Settings'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

final class ScipAcceptanceTranslationProbe
{
    public function read(): array
    {
        return [
            __('scip-acceptance.messages.welcome'),
            trans_choice('scip-acceptance.items.count', 2),
            __('Acceptance JSON Greeting'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AcceptanceUser;

final class AcceptanceModelProbe
{
    public function mutate(AcceptanceUser $user): array
    {
        $before = $user->display_name;
        $user->display_name = 'Updated acceptance name';
        $nickname = $user->nickname;
        $user->nickname = 'updated-acceptance-user';
        $summary = $user->declaredSummary();
        $slug = AcceptanceUser::declaredSlug();
        $label = AcceptanceUser::DEFAULT_LABEL;

        return [$before, $user->display_name, $nickname, $user->nickname, $summary, $slug, $label];
    }
}

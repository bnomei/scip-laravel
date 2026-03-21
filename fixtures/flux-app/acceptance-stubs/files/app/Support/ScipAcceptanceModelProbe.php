<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Member;

final class ScipAcceptanceModelProbe
{
    public function touch(Member $member): array
    {
        $name = $member->name;
        $member->email = 'acceptance@example.test';
        $member->role = 'editor';

        return [$name, $member->id];
    }
}

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('scip.acceptance.{member}', static function (User $user, int $member): bool {
    return $user->id === $member;
});

Broadcast::channel('acceptance.{userId}', static function (User $user, int $userId): bool {
    return $user->id === $userId;
});

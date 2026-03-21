<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AcceptanceUser;

final class AcceptanceUserPolicy
{
    public function view(AcceptanceUser $user): bool
    {
        return true;
    }

    public function update(AcceptanceUser $user): bool
    {
        return true;
    }

    public function delete(AcceptanceUser $user): bool
    {
        return false;
    }
}

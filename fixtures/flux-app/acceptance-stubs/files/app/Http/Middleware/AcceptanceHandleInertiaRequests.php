<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AcceptanceUser;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class AcceptanceHandleInertiaRequests extends Middleware
{
    protected $withAllErrors = true;

    public function share(Request $request): array
    {
        return [
            'headline' => 'shared headline',
            'auth' => [
                'user' => new AcceptanceUser(),
            ],
        ];
    }
}

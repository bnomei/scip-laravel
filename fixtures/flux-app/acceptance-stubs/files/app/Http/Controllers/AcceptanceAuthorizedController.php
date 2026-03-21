<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;

final class AcceptanceAuthorizedController extends Controller
{
    public function store(): array
    {
        Gate::authorize('manage-acceptance');

        return ['ok' => true];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AcceptanceStatus;
use App\Http\Requests\AcceptanceValidatedRequest;

final class AcceptanceValidatedRouteController extends Controller
{
    public function store(AcceptanceValidatedRequest $request, AcceptanceStatus $status): array
    {
        $request->route('status');

        return [
            'title' => $request->validated('title'),
            'status' => $status->value,
        ];
    }
}

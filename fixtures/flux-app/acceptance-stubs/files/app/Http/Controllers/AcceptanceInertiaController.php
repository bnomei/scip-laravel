<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AcceptanceUser;
use Inertia\Inertia;
use Inertia\Response;

final class AcceptanceInertiaController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Acceptance/Dashboard', [
            'canCreate' => true,
            'filters' => [
                'status' => 'draft',
            ],
            'user' => new AcceptanceUser(),
        ]);
    }

    public function secondary(): Response
    {
        return Inertia::render('Acceptance/Dashboard', [
            'draftCount' => 4,
            'filters' => [
                'status' => 'published',
            ],
        ]);
    }
}

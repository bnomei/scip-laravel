<?php

declare(strict_types=1);

namespace App\Http\Controllers;

final class AcceptanceProfileController extends Controller
{
    public function show(): array
    {
        return ['profile' => 'acceptance'];
    }

    public function edit(): array
    {
        return ['profile' => 'acceptance'];
    }
}

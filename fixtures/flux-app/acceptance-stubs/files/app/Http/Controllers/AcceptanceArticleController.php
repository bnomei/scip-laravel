<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AcceptanceArticleResource;
use App\Support\AcceptanceSummary;

final class AcceptanceArticleController extends Controller
{
    public function index(): AcceptanceSummary
    {
        return new AcceptanceSummary(drafts: 2, published: AcceptanceSummary::DEFAULT_LIMIT);
    }

    public function show(string $article): AcceptanceArticleResource
    {
        return new AcceptanceArticleResource([
            'id' => $article,
            'title' => 'Acceptance Article',
        ]);
    }
}

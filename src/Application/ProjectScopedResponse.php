<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Laravel\Ranger\Collectors\Response;
use Laravel\Surveyor\Analyzer\Analyzer;

/**
 * Vendor routes still exist in the route graph, but response-shape inference only helps for project code.
 */
final class ProjectScopedResponse extends Response
{
    public function __construct(
        Analyzer $analyzer,
        private readonly ProjectRouteAnalysisScope $scope,
    ) {
        parent::__construct($analyzer);
    }

    /**
     * @param array<string, mixed> $action
     * @return list<object|string>
     */
    public function parseResponse(array $action): array
    {
        if (!$this->scope->shouldAnalyze($action)) {
            return [];
        }

        return parent::parseResponse($action);
    }
}

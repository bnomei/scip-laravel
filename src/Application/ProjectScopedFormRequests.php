<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Laravel\Ranger\Collectors\FormRequests;
use Laravel\Ranger\Components\Validator;
use Laravel\Surveyor\Analyzer\Analyzer;

/**
 * Skip expensive validator inference for framework and package routes during indexing.
 */
final class ProjectScopedFormRequests extends FormRequests
{
    public function __construct(
        Analyzer $analyzer,
        private readonly ProjectRouteAnalysisScope $scope,
    ) {
        parent::__construct($analyzer);
    }

    /**
     * @param array<string, mixed> $action
     */
    public function getValidator(array $action): ?Validator
    {
        if (!$this->scope->shouldAnalyze($action)) {
            return null;
        }

        return parent::getValidator($action);
    }
}

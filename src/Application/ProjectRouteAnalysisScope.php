<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use ReflectionClass;
use ReflectionException;

use function class_exists;
use function explode;
use function is_string;
use function realpath;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * Ranger's route collector is dominated by deep Surveyor analysis of vendor routes.
 * We keep collecting those routes, but only analyze controller internals for project code.
 */
final readonly class ProjectRouteAnalysisScope
{
    public function __construct(
        private string $projectRoot,
    ) {}

    /**
     * @param array<string, mixed> $action
     */
    public function shouldAnalyze(array $action): bool
    {
        $uses = $action['uses'] ?? null;

        if (!is_string($uses) || !str_contains($uses, '@')) {
            return false;
        }

        [$controller] = explode('@', $uses, 2);

        if ($controller === '' || !class_exists($controller)) {
            return false;
        }

        try {
            $filePath = (new ReflectionClass($controller))->getFileName();
        } catch (ReflectionException) {
            return false;
        }

        if (!is_string($filePath) || $filePath === '') {
            return false;
        }

        $resolvedProjectRoot = realpath($this->projectRoot) ?: $this->projectRoot;
        $resolvedFilePath = realpath($filePath) ?: $filePath;
        $projectPrefix = rtrim($resolvedProjectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (
            !str_starts_with($resolvedFilePath, $projectPrefix)
            && $resolvedFilePath !== rtrim($resolvedProjectRoot, DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        return !str_contains($resolvedFilePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR);
    }
}

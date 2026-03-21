<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Composer\InstalledVersions;
use Laravel\Surveyor\Analyzer\AnalyzedCache;

use function hash;
use function is_dir;
use function is_string;
use function mkdir;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Surveyor ships a persistent analysis cache; scip-laravel enables it at runtime
 * so target projects do not need to set global environment flags for indexing.
 */
final readonly class SurveyorCacheConfigurator
{
    public function configure(string $projectRoot): void
    {
        $cacheRoot = $this->cacheRoot($projectRoot);

        if (!is_dir($cacheRoot)) {
            mkdir($cacheRoot, 0755, true);
        }

        AnalyzedCache::setCacheDirectory($cacheRoot);
        AnalyzedCache::setKey($this->cacheKey($projectRoot));
        AnalyzedCache::enable();
    }

    private function cacheKey(string $projectRoot): string
    {
        return hash('sha256', implode('|', [
            'scip-laravel',
            $this->surveyorVersion(),
            (string) PHP_VERSION_ID,
            realpath($projectRoot) ?: $projectRoot,
        ]));
    }

    private function cacheRoot(string $projectRoot): string
    {
        $resolvedProjectRoot = realpath($projectRoot) ?: $projectRoot;

        return sprintf(
            '%s%s%s%s%s%s%s',
            sys_get_temp_dir(),
            DIRECTORY_SEPARATOR,
            'scip-laravel',
            DIRECTORY_SEPARATOR,
            $this->surveyorVersion(),
            DIRECTORY_SEPARATOR,
            hash('sha256', $resolvedProjectRoot),
        );
    }

    private function surveyorVersion(): string
    {
        $version = InstalledVersions::getPrettyVersion('laravel/surveyor');

        return is_string($version) && $version !== '' ? $version : 'unknown-surveyor';
    }
}

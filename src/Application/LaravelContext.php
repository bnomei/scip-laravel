<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Config\RuntimeConfiguration;
use Bnomei\ScipLaravel\Config\RuntimeMode;
use Bnomei\ScipLaravel\Support\DiagnosticsSink;
use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Laravel\Surveyor\Analyzer\Analyzer;
use Scip\Index;

use function array_filter;
use function implode;
use function in_array;
use function ltrim;
use function method_exists;
use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

final readonly class LaravelContext
{
    /**
     * @param list<string> $enabledFeatures
     */
    public function __construct(
        public string $projectRoot,
        public RuntimeConfiguration $config,
        public RuntimeMode $mode,
        public object $application,
        public ?object $consoleKernel,
        public Analyzer $analyzer,
        public SurveyorMetadataRepository $surveyor,
        public RangerSnapshot $rangerSnapshot,
        public Index $baselineIndex,
        public DiagnosticsSink $diagnostics,
        public array $enabledFeatures,
    ) {}

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->enabledFeatures, true);
    }

    public function projectPath(string ...$segments): string
    {
        $parts = [$this->projectRoot];

        foreach ($segments as $segment) {
            if ($segment !== '') {
                $parts[] = ltrim($segment, DIRECTORY_SEPARATOR);
            }
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    public function appPath(string $path = ''): string
    {
        if (method_exists($this->application, 'path')) {
            $resolved = $this->application->path($path);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return $this->projectPath(...array_filter(['app', $path], static fn(string $segment): bool => $segment !== ''));
    }

    public function configPath(string $path = ''): string
    {
        if (method_exists($this->application, 'configPath')) {
            $resolved = $this->application->configPath($path);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return $this->projectPath(...array_filter(
            ['config', $path],
            static fn(string $segment): bool => $segment !== '',
        ));
    }

    public function relativeProjectPath(string $path): string
    {
        $resolvedRoot = realpath($this->projectRoot) ?: $this->projectRoot;
        $resolvedPath = realpath($path) ?: $path;
        $root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($resolvedPath === rtrim($resolvedRoot, DIRECTORY_SEPARATOR)) {
            return '.';
        }

        if (str_starts_with($resolvedPath, $root)) {
            return substr($resolvedPath, strlen($root));
        }

        return $path;
    }
}

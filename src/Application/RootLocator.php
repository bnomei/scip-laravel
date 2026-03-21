<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use function getcwd;
use function is_dir;
use function is_file;
use function preg_match;
use function realpath;
use function sprintf;
use function str_starts_with;

final class RootLocator
{
    public function resolve(?string $candidate): string
    {
        $candidate ??= getcwd()
        ?: throw new RootDetectionException('Could not determine the current working directory.');
        $candidate = $this->absolutize($candidate);
        $resolved = realpath($candidate);
        $targetRoot = $resolved !== false ? $resolved : $candidate;

        if (!is_dir($targetRoot)) {
            throw new RootDetectionException(sprintf(
                'Target root does not exist or is not a directory: %s',
                $targetRoot,
            ));
        }

        if (!is_file($targetRoot . DIRECTORY_SEPARATOR . 'bootstrap/app.php')) {
            throw new RootDetectionException(sprintf(
                'Target root is not a supported Laravel application: missing bootstrap/app.php in %s',
                $targetRoot,
            ));
        }

        if (!is_file($targetRoot . DIRECTORY_SEPARATOR . 'artisan')) {
            throw new RootDetectionException(sprintf(
                'Target root is not a supported Laravel application: missing artisan in %s',
                $targetRoot,
            ));
        }

        return $targetRoot;
    }

    private function absolutize(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            throw new RootDetectionException('Could not determine the current working directory.');
        }

        return $cwd . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return (
            $path !== ''
            && (
                $path[0] === DIRECTORY_SEPARATOR
                || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)
                || str_starts_with($path, '\\\\')
            )
        );
    }
}

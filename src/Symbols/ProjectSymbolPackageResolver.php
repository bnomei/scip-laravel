<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Symbols;

use RuntimeException;

use function is_array;
use function is_string;
use function sprintf;

final class ProjectSymbolPackageResolver
{
    /** @var array<string, ProjectSymbolPackage> */
    private static array $cache = [];

    public function resolve(string $projectRoot): ProjectSymbolPackage
    {
        if (isset(self::$cache[$projectRoot])) {
            return self::$cache[$projectRoot];
        }

        $installedPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor/composer/installed.php';

        if (!is_file($installedPath)) {
            throw new RuntimeException(sprintf('Cannot resolve project symbol package without %s.', $installedPath));
        }

        $installed = require $installedPath;

        if (!is_array($installed) || !is_array($installed['root'] ?? null)) {
            throw new RuntimeException(sprintf('Installed Composer metadata is invalid: %s.', $installedPath));
        }

        $root = $installed['root'];
        $name = is_string($root['name'] ?? null) && $root['name'] !== '' ? $root['name'] : null;
        $version = is_string($root['reference'] ?? null) && $root['reference'] !== ''
            ? $root['reference']
            : (is_string($root['version'] ?? null) && $root['version'] !== '' ? $root['version'] : null);

        if ($name === null || $version === null) {
            throw new RuntimeException(sprintf(
                'Installed Composer metadata is missing root package name/version: %s.',
                $installedPath,
            ));
        }

        self::$cache[$projectRoot] = new ProjectSymbolPackage(manager: 'composer', name: $name, version: $version);

        return self::$cache[$projectRoot];
    }
}

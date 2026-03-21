<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Runtime;

use Bnomei\ScipLaravel\Config\RuntimeConfiguration;
use Bnomei\ScipLaravel\Config\RuntimeMode;

use function array_key_exists;
use function dirname;
use function extension_loaded;
use function file_exists;
use function is_dir;
use function mkdir;
use function putenv;
use function sprintf;

final class RuntimeModeApplier
{
    public function apply(string $targetRoot, RuntimeConfiguration $config): RuntimeOverrideSnapshot
    {
        if ($config->mode !== RuntimeMode::Safe) {
            return new RuntimeOverrideSnapshot([]);
        }

        $sqlitePath = $targetRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'scip-laravel.sqlite';
        $databaseOverrides = extension_loaded('pdo_sqlite')
            ? [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $sqlitePath,
            ]
            : [];

        if ($databaseOverrides !== [] && !file_exists($sqlitePath)) {
            $directory = dirname($sqlitePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            touch($sqlitePath);
        }

        $overrides = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'CACHE_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'QUEUE_DRIVER' => 'sync',
            'MAIL_MAILER' => 'array',
            'MAIL_DRIVER' => 'array',
            'FILESYSTEM_DISK' => 'local',
            'FILESYSTEM_DRIVER' => 'local',
            'BROADCAST_CONNECTION' => 'log',
            'BROADCAST_DRIVER' => 'log',
            ...$databaseOverrides,
        ];

        $previous = [];

        foreach ($overrides as $key => $value) {
            $previous[$key] = array_key_exists($key, $_ENV)
                ? (string) $_ENV[$key]
                : (array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null);

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return new RuntimeOverrideSnapshot($previous);
    }
}

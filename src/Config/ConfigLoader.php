<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Config;

use Bnomei\ScipLaravel\Console\CliInput;
use ValueError;

use function array_key_exists;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function realpath;
use function sprintf;
use function str_starts_with;
use function trim;

final class ConfigLoader
{
    private const DEFAULT_CONFIG = 'scip-laravel.php';

    private const DEFAULT_OUTPUT = 'index.scip';

    /**
     * @var list<non-empty-string>
     */
    private const DEFAULT_FEATURES = [
        'models',
        'routes',
        'views',
        'inertia',
        'broadcast',
        'config',
        'translations',
        'env',
    ];

    public function load(string $targetRoot, CliInput $input): RuntimeConfiguration
    {
        $configFile = $input->configPath ?? self::DEFAULT_CONFIG;
        $configPath = $this->resolvePath($targetRoot, $configFile);
        $config = [];
        $configLoaded = false;

        if (is_file($configPath)) {
            $loaded = require $configPath;

            if (!is_array($loaded)) {
                throw new ConfigException(sprintf('Config file must return an array: %s', $configPath));
            }

            $config = $loaded;
            $configLoaded = true;
        }

        $outputValue = $input->outputPath ?? $this->stringValue($config, 'output') ?? self::DEFAULT_OUTPUT;
        $modeValue = $input->mode ?? $this->stringValue($config, 'mode') ?? RuntimeMode::Full->value;
        $strict = $input->strict || $this->boolValue($config, 'strict', false);
        $features = $input->features !== []
            ? $this->normalizeFeatures($input->features)
            : $this->normalizeFeatures($config['features'] ?? self::DEFAULT_FEATURES);

        try {
            $mode = RuntimeMode::from($modeValue);
        } catch (ValueError) {
            throw new ConfigException(sprintf(
                "Unsupported runtime mode '%s'. Expected one of: safe, full.",
                $modeValue,
            ));
        }

        return new RuntimeConfiguration(
            configPath: $configPath,
            configLoaded: $configLoaded,
            outputPath: $this->resolvePath($targetRoot, $outputValue),
            mode: $mode,
            strict: $strict,
            features: $features,
        );
    }

    private function stringValue(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];

        if (!is_string($value) || trim($value) === '') {
            throw new ConfigException(sprintf("Config key '%s' must be a non-empty string.", $key));
        }

        return $value;
    }

    private function boolValue(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];

        if (!is_bool($value)) {
            throw new ConfigException(sprintf("Config key '%s' must be a boolean.", $key));
        }

        return $value;
    }

    /**
     * @param mixed $raw
     * @return list<non-empty-string>
     */
    private function normalizeFeatures(mixed $raw): array
    {
        $enabled = [];

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            throw new ConfigException(
                "Config key 'features' must be a list, a comma-separated string, or a boolean map.",
            );
        }

        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                if (!is_bool($value)) {
                    throw new ConfigException('Boolean feature maps must use true/false values.');
                }

                if ($value) {
                    $enabled[] = $key;
                }

                continue;
            }

            if (!is_string($value)) {
                throw new ConfigException('Feature lists must contain only strings.');
            }

            $feature = trim($value);

            if ($feature !== '') {
                $enabled[] = $feature;
            }
        }

        if ($enabled === []) {
            throw new ConfigException('At least one feature must be enabled.');
        }

        $ordered = [];

        foreach (self::DEFAULT_FEATURES as $feature) {
            if (in_array($feature, $enabled, true)) {
                $ordered[] = $feature;
            }
        }

        foreach ($enabled as $feature) {
            if (!in_array($feature, self::DEFAULT_FEATURES, true)) {
                throw new ConfigException(sprintf(
                    "Unsupported feature '%s'. Expected one of: %s.",
                    $feature,
                    implode(', ', self::DEFAULT_FEATURES),
                ));
            }
        }

        return $ordered;
    }

    private function resolvePath(string $targetRoot, string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $combined = $targetRoot . DIRECTORY_SEPARATOR . $path;
        $resolved = realpath($combined);

        return $resolved !== false ? $resolved : $combined;
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

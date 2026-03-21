<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Illuminate\Support\Collection;
use Laravel\Ranger\Collectors\InertiaComponents;
use Laravel\Ranger\Components\EnvironmentVariable;
use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Ranger\Ranger;
use ReflectionProperty;

use function array_values;
use function class_exists;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function ksort;
use function preg_match;
use function str_contains;

final class RangerSnapshotFactory
{
    /**
     * @param list<string> $enabledFeatures
     */
    public function collect(string $targetRoot, array $enabledFeatures = []): RangerSnapshot
    {
        $collectRoutes = $this->needsAnyFeature($enabledFeatures, ['routes', 'views', 'inertia']);
        $collectModels = $this->needsAnyFeature($enabledFeatures, ['models']);
        $collectEnums = $collectModels;
        $collectBroadcast = $this->needsAnyFeature($enabledFeatures, ['broadcast']);
        $collectEnvironmentVariables = $this->needsAnyFeature($enabledFeatures, ['env']);
        $collectInertiaSharedData = $this->needsAnyFeature($enabledFeatures, ['inertia']);

        if (
            !$collectRoutes
            && !$collectModels
            && !$collectEnums
            && !$collectBroadcast
            && !$collectEnvironmentVariables
            && !$collectInertiaSharedData
        ) {
            return new RangerSnapshot(
                routes: [],
                models: [],
                enums: [],
                broadcastEvents: [],
                broadcastChannels: [],
                environmentVariables: [],
                inertiaSharedData: [],
                inertiaComponents: [],
            );
        }

        if ($collectInertiaSharedData) {
            $this->resetInertiaComponents();
        }

        $ranger = new Ranger();
        $ranger->setBasePaths($targetRoot);

        $appPath = $targetRoot . DIRECTORY_SEPARATOR . 'app';

        if (is_dir($appPath)) {
            $ranger->setAppPaths($appPath);
        }

        $routes = [];
        $models = [];
        $enums = [];
        $broadcastEvents = [];
        $broadcastChannels = [];
        $environmentVariables = [];
        $inertiaSharedData = [];

        if ($collectRoutes) {
            $ranger->onRoutes(function (Collection $collection) use (&$routes): void {
                $routes = array_values($collection->all());
            });
        }

        if ($collectModels) {
            $ranger->onModels(function (Collection $collection) use (&$models): void {
                $models = array_values($collection->all());
            });
        }

        if ($collectEnums) {
            $ranger->onEnums(function (Collection $collection) use (&$enums): void {
                $enums = array_values($collection->all());
            });
        }

        if ($collectBroadcast) {
            $ranger->onBroadcastEvents(function (Collection $collection) use (&$broadcastEvents): void {
                $broadcastEvents = array_values($collection->all());
            });

            $ranger->onBroadcastChannels(function (Collection $collection) use (&$broadcastChannels): void {
                $broadcastChannels = array_values($collection->all());
            });
        }

        if ($collectEnvironmentVariables && class_exists(\Dotenv\Dotenv::class)) {
            $ranger->onEnvironmentVariables(function (Collection $collection) use (&$environmentVariables): void {
                $environmentVariables = array_values($collection->all());
            });
        }

        if ($collectInertiaSharedData) {
            $ranger->onInertiaSharedData(function (object $item) use (&$inertiaSharedData): void {
                $inertiaSharedData[] = $item;
            });
        }

        $ranger->walk();

        if ($collectEnvironmentVariables && $environmentVariables === [] && !class_exists(\Dotenv\Dotenv::class)) {
            $environmentVariables = $this->fallbackEnvironmentVariables($targetRoot);
        }

        if ($environmentVariables !== []) {
            usort($environmentVariables, static function (object $left, object $right): int {
                $leftKey = is_string($left->key ?? null) ? $left->key : '';
                $rightKey = is_string($right->key ?? null) ? $right->key : '';

                return $leftKey <=> $rightKey;
            });
        }

        $snapshot = new RangerSnapshot(
            routes: $routes,
            models: $models,
            enums: $enums,
            broadcastEvents: $broadcastEvents,
            broadcastChannels: $broadcastChannels,
            environmentVariables: $environmentVariables,
            inertiaSharedData: $inertiaSharedData,
            inertiaComponents: $collectInertiaSharedData ? $this->inertiaComponents() : [],
        );

        if ($collectInertiaSharedData) {
            $this->resetInertiaComponents();
        }

        return $snapshot;
    }

    /**
     * @param list<string> $enabledFeatures
     * @param list<string> $requiredFeatures
     */
    private function needsAnyFeature(array $enabledFeatures, array $requiredFeatures): bool
    {
        foreach ($requiredFeatures as $feature) {
            if (in_array($feature, $enabledFeatures, true)) {
                return true;
            }
        }

        return false;
    }

    private function resetInertiaComponents(): void
    {
        $property = new ReflectionProperty(InertiaComponents::class, 'components');
        $property->setValue([]);
    }

    /**
     * @return array<string, object>
     */
    private function inertiaComponents(): array
    {
        $property = new ReflectionProperty(InertiaComponents::class, 'components');
        $raw = $property->getValue();

        if (!is_array($raw)) {
            return [];
        }

        $components = [];

        foreach ($raw as $name => $props) {
            if (!is_string($name) || $name === '' || !is_array($props)) {
                continue;
            }

            $components[$name] = new InertiaResponse($name, $props);
        }

        ksort($components);

        return $components;
    }

    /**
     * @return list<EnvironmentVariable>
     */
    private function fallbackEnvironmentVariables(string $targetRoot): array
    {
        $envPath = $targetRoot . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath)) {
            return [];
        }

        $contents = file_get_contents($envPath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $variables = [];
        $matched = preg_match_all(
            '/^(?:\s*export\s+)?(?<key>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?<value>.*)$/m',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched !== false) {
            foreach ($matches['key'] ?? [] as $index => [$key]) {
                if (!is_string($key) || $key === '' || isset($variables[$key])) {
                    continue;
                }

                $value = $matches['value'][$index][0] ?? '';

                $variables[$key] = new EnvironmentVariable(
                    key: $key,
                    value: $this->resolveEnvironmentVariableValue($value),
                );
            }
        }

        ksort($variables);

        return array_values($variables);
    }

    private function resolveEnvironmentVariableValue(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}

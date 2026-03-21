<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\Contracts\Type as SurveyorType;
use Laravel\Surveyor\Types\Type;
use Laravel\Surveyor\Types\UnionType;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_object;
use function is_string;
use function ksort;
use function max;
use function min;
use function sort;

final class TopLevelTypeContractFormatter
{
    public function __construct(
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
    ) {}

    /**
     * @param list<ArrayType> $shapes
     */
    public function formatArrayShapes(array $shapes, string $prefix, int $limit = 6): ?string
    {
        if ($shapes === []) {
            return null;
        }

        $totalShapes = count($shapes);
        $contracts = [];

        foreach ($shapes as $shape) {
            foreach ($shape->value as $key => $type) {
                if (!is_string($key) || $key === '' || !$type instanceof SurveyorType) {
                    continue;
                }

                $contracts[$key]['count'] ??= 0;
                $contracts[$key]['count']++;
                $contracts[$key]['types'][] = $type;
            }
        }

        if ($contracts === []) {
            return null;
        }

        ksort($contracts);
        $parts = [];
        $remaining = 0;

        foreach ($contracts as $key => $payload) {
            if (count($parts) >= max(1, $limit)) {
                $remaining++;
                continue;
            }

            $typeNames = [];

            foreach ($payload['types'] as $type) {
                if (!$type instanceof SurveyorType) {
                    continue;
                }

                $typeNames[] = $this->typeFormatter->format($type);
            }

            $typeNames = array_values(array_unique(array_filter(
                $typeNames,
                static fn(string $name): bool => $name !== '',
            )));
            sort($typeNames);

            if ($typeNames === []) {
                continue;
            }

            $optional = ($payload['count'] ?? 0) < $totalShapes || $this->hasOptionalType($payload['types']);

            $parts[] = $key . ($optional ? '?' : '') . ': ' . implode('|', $typeNames);
        }

        if ($parts === []) {
            return null;
        }

        if ($remaining > 0) {
            $parts[] = '+' . $remaining . ' more';
        }

        return $prefix . ': ' . implode(', ', $parts);
    }

    /**
     * @param list<ArrayType> $shapes
     * @return list<string>
     */
    public function topLevelKeys(array $shapes): array
    {
        $keys = [];

        foreach ($shapes as $shape) {
            foreach (array_keys($shape->value) as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    public function formatTypeContract(SurveyorType $type, string $prefix): ?string
    {
        if ($type instanceof ArrayType) {
            return $this->formatArrayShapes([$type], $prefix);
        }

        if ($type instanceof UnionType) {
            $arrayShapes = array_values(array_filter(
                $type->types,
                static fn(mixed $member): bool => $member instanceof ArrayType,
            ));

            if ($arrayShapes !== []) {
                return $this->formatArrayShapes($arrayShapes, $prefix);
            }
        }

        $formatted = $this->typeFormatter->format($type);

        return $formatted === '' ? null : $prefix . ': ' . $formatted;
    }

    /**
     * @param list<mixed> $types
     */
    private function hasOptionalType(array $types): bool
    {
        foreach ($types as $type) {
            if (is_object($type) && method_exists($type, 'isOptional') && $type->isOptional()) {
                return true;
            }
        }

        return false;
    }
}

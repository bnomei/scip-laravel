<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Laravel\Surveyor\Types\ArrayShapeType;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\BoolType;
use Laravel\Surveyor\Types\CallableType;
use Laravel\Surveyor\Types\ClassType;
use Laravel\Surveyor\Types\Contracts\Type as SurveyorType;
use Laravel\Surveyor\Types\FloatType;
use Laravel\Surveyor\Types\IntersectionType;
use Laravel\Surveyor\Types\IntType;
use Laravel\Surveyor\Types\MixedType;
use Laravel\Surveyor\Types\NeverType;
use Laravel\Surveyor\Types\NullType;
use Laravel\Surveyor\Types\NumberType;
use Laravel\Surveyor\Types\ObjectType;
use Laravel\Surveyor\Types\StringType;
use Laravel\Surveyor\Types\TemplateTagType;
use Laravel\Surveyor\Types\UnionType;
use Laravel\Surveyor\Types\VoidType;

use function array_is_list;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function sort;

final class SurveyorTypeFormatter
{
    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function format(SurveyorType $type): string
    {
        $key = $this->cacheKey($type);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($type instanceof UnionType) {
            return $this->cache[$key] = $this->formatUnion($type);
        }

        $formatted = $this->formatBare($type);

        if ($type->isNullable()) {
            return $this->cache[$key] = '?' . $formatted;
        }

        return $this->cache[$key] = $formatted;
    }

    private function formatUnion(UnionType $type): string
    {
        $parts = [];
        $nullable = false;

        foreach ($type->types as $member) {
            if (!$member instanceof SurveyorType) {
                continue;
            }

            $nullable = $nullable || $member->isNullable();
            $parts[] = $this->formatBare($member);
        }

        if ($nullable) {
            $parts[] = 'null';
        }

        $parts = array_values(array_unique($parts));
        sort($parts);

        return implode('|', $parts);
    }

    private function formatBare(SurveyorType $type): string
    {
        return match (true) {
            $type instanceof TemplateTagType => $this->formatTemplateTagType($type),
            $type instanceof IntersectionType => $this->formatIntersectionType($type),
            $type instanceof ClassType => $this->formatClassType($type),
            $type instanceof ArrayType => $this->formatArrayType($type),
            $type instanceof ArrayShapeType => 'array<'
                . $this->format($type->keyType)
                . ', '
                . $this->format($type->valueType)
                . '>',
            $type instanceof CallableType => 'callable',
            $type instanceof StringType => 'string',
            $type instanceof IntType => 'int',
            $type instanceof FloatType => 'float',
            $type instanceof BoolType => 'bool',
            $type instanceof NumberType => 'number',
            $type instanceof ObjectType => 'object',
            $type instanceof NullType => 'null',
            $type instanceof VoidType => 'void',
            $type instanceof NeverType => 'never',
            $type instanceof MixedType => 'mixed',
            default => 'mixed',
        };
    }

    private function formatIntersectionType(IntersectionType $type): string
    {
        $parts = [];

        foreach ($type->types as $member) {
            if (!$member instanceof SurveyorType) {
                continue;
            }

            $parts[] = $this->format($member);
        }

        $parts = array_values(array_unique(array_filter($parts, static fn(string $value): bool => $value !== 'mixed')));
        sort($parts);

        return $parts === [] ? 'mixed' : implode('&', $parts);
    }

    private function formatTemplateTagType(TemplateTagType $type): string
    {
        $formatted = $type->name;
        $clauses = [];

        if ($type->bound instanceof SurveyorType) {
            $clauses[] = 'of ' . $this->format($type->bound);
        }

        if ($type->lowerBound instanceof SurveyorType) {
            $clauses[] = 'super ' . $this->format($type->lowerBound);
        }

        if ($type->default instanceof SurveyorType) {
            $clauses[] = '= ' . $this->format($type->default);
        }

        if ($clauses !== []) {
            $formatted .= ' ' . implode(' ', $clauses);
        }

        if (is_string($type->description) && $type->description !== '') {
            $formatted .= ' - ' . $type->description;
        }

        return $formatted;
    }

    private function formatClassType(ClassType $type): string
    {
        $resolved = $type->resolved();
        $genericTypes = $type->genericTypes();

        if ($genericTypes === []) {
            return $resolved;
        }

        return (
            $resolved
            . '<'
            . implode(', ', array_map(fn(SurveyorType $genericType): string => $this->format(
                $genericType,
            ), $genericTypes))
            . '>'
        );
    }

    private function formatArrayType(ArrayType $type): string
    {
        if ($type->value === []) {
            return 'array<mixed>';
        }

        if (!array_is_list($type->value) && count($type->value) <= 4) {
            $items = $type->value;
            ksort($items);
            $parts = [];

            foreach ($items as $key => $valueType) {
                $keyLabel = is_int($key) ? (string) $key : (is_string($key) ? $key : 'key');
                $parts[] = $keyLabel . ($valueType->isOptional() ? '?: ' : ': ') . $this->format($valueType);
            }

            return 'array{' . implode(', ', $parts) . '}';
        }

        if (array_is_list($type->value)) {
            return 'array<' . $this->format($type->valueType()) . '>';
        }

        return 'array<' . $this->format($type->keyType()) . ', ' . $this->format($type->valueType()) . '>';
    }

    private function cacheKey(SurveyorType $type): string
    {
        return match (true) {
            $type instanceof UnionType => 'union:' . $this->unionKey($type) . ($type->isNullable() ? ':nullable' : ''),
            $type instanceof IntersectionType => 'intersection:'
                . $this->intersectionKey($type)
                . ($type->isNullable() ? ':nullable' : ''),
            $type instanceof TemplateTagType => 'template:'
                . $this->templateTagKey($type)
                . ($type->isNullable() ? ':nullable' : ''),
            default => get_class($type) . ':' . $type->toString() . ($type->isNullable() ? ':nullable' : ''),
        };
    }

    private function unionKey(UnionType $type): string
    {
        $parts = [];

        foreach ($type->types as $member) {
            if (!$member instanceof SurveyorType) {
                continue;
            }

            $parts[] = $this->typeFingerprint($member);
        }

        sort($parts);

        return implode('|', $parts);
    }

    private function intersectionKey(IntersectionType $type): string
    {
        $parts = [];

        foreach ($type->types as $member) {
            if (!$member instanceof SurveyorType) {
                continue;
            }

            $parts[] = $this->typeFingerprint($member);
        }

        sort($parts);

        return implode('&', $parts);
    }

    private function templateTagKey(TemplateTagType $type): string
    {
        return implode('|', [
            $type->name,
            $this->fingerprintOrEmpty($type->bound),
            $this->fingerprintOrEmpty($type->default),
            $this->fingerprintOrEmpty($type->lowerBound),
            is_string($type->description) ? $type->description : '',
        ]);
    }

    private function fingerprintOrEmpty(mixed $value): string
    {
        return $value instanceof SurveyorType ? $this->typeFingerprint($value) : '';
    }

    private function typeFingerprint(SurveyorType $type): string
    {
        return match (true) {
            $type instanceof UnionType => 'union(' . $this->unionKey($type) . ')',
            $type instanceof IntersectionType => 'intersection(' . $this->intersectionKey($type) . ')',
            $type instanceof TemplateTagType => 'template(' . $this->templateTagKey($type) . ')',
            default => get_class($type) . ':' . $type->toString() . ($type->isNullable() ? ':nullable' : ''),
        };
    }
}

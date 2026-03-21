<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Laravel\Ranger\Validation\Rule as RangerRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

use function array_map;
use function array_unique;
use function array_values;
use function class_basename;
use function count;
use function get_class;
use function implode;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function ksort;
use function sort;
use function strtolower;

final class ValidationRuleFormatter
{
    /**
     * @param array<string, array<int, array{0: mixed, 1?: array<int, mixed>}>> $rulesByKey
     */
    public function formatSurveyorRuleMap(array $rulesByKey): string
    {
        ksort($rulesByKey);
        $pairs = [];

        foreach ($rulesByKey as $key => $rules) {
            $formatted = $this->formatSurveyorRules($rules);

            if ($formatted === '') {
                continue;
            }

            $pairs[] = $key . ' => ' . $formatted;
        }

        return implode('; ', $pairs);
    }

    /**
     * @param list<array{0: mixed, 1?: array<int, mixed>}> $rules
     */
    public function formatSurveyorRules(array $rules): string
    {
        $formatted = [];

        foreach ($rules as $rule) {
            $value = $this->formatParsedRule($rule);

            if ($value !== '') {
                $formatted[] = $value;
            }
        }

        $formatted = array_values(array_unique($formatted));
        sort($formatted);

        return implode('|', $formatted);
    }

    /**
     * @param list<RangerRule> $rules
     */
    public function formatRangerRules(array $rules): string
    {
        $formatted = [];

        foreach ($rules as $rule) {
            $value = $this->formatRangerRule($rule);

            if ($value !== '') {
                $formatted[] = $value;
            }
        }

        $formatted = array_values(array_unique($formatted));
        sort($formatted);

        return implode('|', $formatted);
    }

    /**
     * @param array<string, list<RangerRule>> $rulesByKey
     */
    public function formatRangerRuleMap(array $rulesByKey): string
    {
        ksort($rulesByKey);
        $pairs = [];

        foreach ($rulesByKey as $key => $rules) {
            $formatted = $this->formatRangerRules($rules);

            if ($formatted === '') {
                continue;
            }

            $pairs[] = $key . ' => ' . $formatted;
        }

        return implode('; ', $pairs);
    }

    public function formatLiteralRuleMap(Array_ $array): string
    {
        $pairs = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
                continue;
            }

            $formatted = $this->formatLiteralRuleExpression($item->value);

            if ($formatted === '') {
                continue;
            }

            $pairs[$item->key->value] = $item->key->value . ' => ' . $formatted;
        }

        ksort($pairs);

        return implode('; ', array_values($pairs));
    }

    public function formatLiteralRuleExpression(Expr $expression): string
    {
        if ($expression instanceof String_) {
            return $this->normalizeDelimitedRules($expression->value);
        }

        if ($expression instanceof Array_) {
            $formatted = [];

            foreach ($expression->items as $item) {
                if (!$item instanceof ArrayItem || !$item->value instanceof Expr) {
                    continue;
                }

                $value = $this->formatLiteralRuleValue($item->value);

                if ($value !== '') {
                    $formatted[] = $value;
                }
            }

            $formatted = array_values(array_unique($formatted));
            sort($formatted);

            return implode('|', $formatted);
        }

        return $this->formatLiteralRuleValue($expression);
    }

    /**
     * @param array{0: mixed, 1?: array<int, mixed>} $rule
     */
    private function formatParsedRule(array $rule): string
    {
        $name = $this->formatRuleName($rule[0] ?? null);
        $params = $this->formatParams($rule[1] ?? []);

        if ($name === '') {
            return '';
        }

        return $params === '' ? $name : $name . ':' . $params;
    }

    private function formatRangerRule(RangerRule $rule): string
    {
        if ($rule->isEnum()) {
            return 'enum';
        }

        $name = $this->formatRuleName($rule->rule());
        $params = $this->formatParams($rule->getParams());

        if ($name === '') {
            return '';
        }

        return $params === '' ? $name : $name . ':' . $params;
    }

    private function normalizeDelimitedRules(string $rules): string
    {
        $parts = array_values(array_filter(array_map(
            static fn(string $part): string => strtolower(trim($part)),
            explode('|', $rules),
        )));
        $parts = array_values(array_unique($parts));
        sort($parts);

        return implode('|', $parts);
    }

    private function formatLiteralRuleValue(Expr $expression): string
    {
        if ($expression instanceof String_) {
            return $this->normalizeDelimitedRules($expression->value);
        }

        if ($expression instanceof StaticCall) {
            $name = $expression->name instanceof Identifier ? strtolower($expression->name->toString()) : '';
            $params = $this->formatLiteralArgs($expression->getArgs());

            if ($name === '') {
                return '';
            }

            return $params === '' ? $name : $name . ':' . $params;
        }

        if ($expression instanceof New_ && $expression->class instanceof Name) {
            return strtolower(class_basename($expression->class->toString()));
        }

        if (
            $expression instanceof ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier
            && strtolower($expression->name->toString()) === 'class'
        ) {
            return strtolower(class_basename($expression->class->toString()));
        }

        if ($expression instanceof Array_) {
            return $this->formatLiteralRuleExpression($expression);
        }

        return '';
    }

    /**
     * @param array<int, Arg> $args
     */
    private function formatLiteralArgs(array $args): string
    {
        $formatted = [];

        foreach ($args as $arg) {
            $value = $arg->value;

            if ($value instanceof String_) {
                $formatted[] = $value->value;
                continue;
            }

            if ($value instanceof Array_) {
                $items = [];

                foreach ($value->items as $item) {
                    if ($item instanceof ArrayItem && $item->value instanceof String_) {
                        $items[] = $item->value->value;
                    }
                }

                if ($items !== []) {
                    $formatted[] = implode(',', $items);
                }
            }
        }

        return implode(',', $formatted);
    }

    private function formatRuleName(mixed $rule): string
    {
        if (is_string($rule) && $rule !== '') {
            return strtolower($rule);
        }

        if (is_object($rule)) {
            return strtolower(class_basename(get_class($rule)));
        }

        return '';
    }

    /**
     * @param array<int, mixed> $params
     */
    private function formatParams(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $formatted = array_values(array_filter(array_map(function (mixed $value): ?string {
            if (is_scalar($value)) {
                return (string) $value;
            }

            if (is_object($value)) {
                return class_basename(get_class($value));
            }

            if (is_array($value)) {
                return implode(
                    ',',
                    array_values(array_filter(array_map(static fn(mixed $item): ?string => is_scalar($item)
                        ? (string) $item
                        : null, $value))),
                );
            }

            return null;
        }, $params)));

        if (count($formatted) === 0) {
            return '';
        }

        return implode(',', $formatted);
    }
}

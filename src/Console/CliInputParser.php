<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Console;

use function array_values;
use function count;
use function explode;
use function in_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function trim;

final class CliInputParser
{
    /**
     * @param list<string> $argv
     */
    public function parse(array $argv): CliInput
    {
        $help = false;
        $strict = false;
        $targetRoot = null;
        $outputPath = null;
        $configPath = null;
        $mode = null;
        $features = [];
        $positionals = [];

        for ($index = 1; $index < count($argv); $index++) {
            $argument = $argv[$index];

            if ($argument === '-h' || $argument === '--help') {
                $help = true;
                continue;
            }

            if ($argument === '--strict') {
                $strict = true;
                continue;
            }

            if ($argument === '--') {
                $positionals = [...$positionals, ...array_slice($argv, $index + 1)];
                break;
            }

            if (str_starts_with($argument, '--output=')) {
                $outputPath = $this->parseInlineValue('--output', $argument);
                continue;
            }

            if (str_starts_with($argument, '--config=')) {
                $configPath = $this->parseInlineValue('--config', $argument);
                continue;
            }

            if (str_starts_with($argument, '--mode=')) {
                $mode = $this->parseInlineValue('--mode', $argument);
                continue;
            }

            if (str_starts_with($argument, '--feature=')) {
                $features = [
                    ...$features,
                    ...$this->splitFeatureValue($this->parseInlineValue('--feature', $argument)),
                ];
                continue;
            }

            if ($argument === '--output') {
                $outputPath = $this->readNextValue('--output', $argv, $index);
                continue;
            }

            if ($argument === '--config') {
                $configPath = $this->readNextValue('--config', $argv, $index);
                continue;
            }

            if ($argument === '--mode') {
                $mode = $this->readNextValue('--mode', $argv, $index);
                continue;
            }

            if ($argument === '--feature') {
                $features = [
                    ...$features,
                    ...$this->splitFeatureValue($this->readNextValue('--feature', $argv, $index)),
                ];
                continue;
            }

            if (str_starts_with($argument, '--')) {
                throw new UsageException("Unknown option: {$argument}");
            }

            $positionals[] = $argument;
        }

        if (count($positionals) > 1) {
            throw new UsageException('Expected at most one target root argument.');
        }

        $targetRoot = $positionals[0] ?? null;

        return new CliInput(
            help: $help,
            targetRoot: $targetRoot,
            outputPath: $outputPath,
            configPath: $configPath,
            mode: $mode,
            strict: $strict,
            features: $this->uniqueList($features),
        );
    }

    /**
     * @param list<string> $argv
     */
    private function readNextValue(string $option, array $argv, int &$index): string
    {
        $next = $argv[$index + 1] ?? null;

        if (!is_string($next) || $next === '' || str_starts_with($next, '--')) {
            throw new UsageException("Missing value for {$option}.");
        }

        $index++;

        return $next;
    }

    private function parseInlineValue(string $option, string $argument): string
    {
        [, $value] = explode('=', $argument, 2);

        if ($value === '') {
            throw new UsageException("Missing value for {$option}.");
        }

        return $value;
    }

    /**
     * @return list<non-empty-string>
     */
    private function splitFeatureValue(string $value): array
    {
        $parts = explode(',', $value);
        $features = [];

        foreach ($parts as $part) {
            $feature = trim($part);

            if ($feature === '') {
                continue;
            }

            $features[] = $feature;
        }

        if ($features === []) {
            throw new UsageException('Expected at least one feature name.');
        }

        return $features;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueList(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (!in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }

        return array_values($unique);
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Enums;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineEnumCaseSymbolResolver;
use Laravel\Ranger\Components\Enum as RangerEnum;
use ReflectionClass;
use Scip\SymbolInformation;
use Throwable;

use function array_values;
use function is_int;
use function is_string;
use function ksort;
use function sort;

final class EnumEnricher implements Enricher
{
    public function __construct(
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineEnumCaseSymbolResolver $caseSymbolResolver = new BaselineEnumCaseSymbolResolver(),
    ) {}

    public function feature(): string
    {
        return 'models';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $symbols = [];
        $enums = [];

        foreach ($context->rangerSnapshot->enums as $enum) {
            if ($enum instanceof RangerEnum) {
                $enums[$enum->name] = $enum;
            }
        }

        ksort($enums);

        foreach ($enums as $className => $enum) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            $filePath = $reflection->getFileName();

            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);
            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $className,
                $reflection->getStartLine(),
            );

            if (is_string($classSymbol) && $classSymbol !== '') {
                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $classSymbol,
                    'documentation' => [$this->classDocumentation($enum)],
                ]));
            }

            $cases = $enum->cases;
            ksort($cases);

            foreach ($cases as $caseName => $value) {
                $caseSymbol = $this->caseSymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    $className,
                    $caseName,
                );

                if (!is_string($caseSymbol) || $caseSymbol === '') {
                    continue;
                }

                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $caseSymbol,
                    'documentation' => [$this->caseDocumentation($value)],
                ]));
            }
        }

        if ($symbols === []) {
            return IndexPatch::empty();
        }

        return new IndexPatch(symbols: $symbols);
    }

    private function classDocumentation(RangerEnum $enum): string
    {
        $cases = [];

        foreach ($enum->cases as $caseName => $value) {
            if (is_string($value)) {
                $cases[] = $caseName . '="' . $value . '"';
                continue;
            }

            if (is_int($value)) {
                $cases[] = $caseName . '=' . $value;
                continue;
            }

            $cases[] = $caseName;
        }

        sort($cases);

        return 'Laravel enum cases: ' . implode(', ', $cases);
    }

    private function caseDocumentation(int|string|null $value): string
    {
        if (is_string($value)) {
            return 'Laravel enum case value: "' . $value . '"';
        }

        if (is_int($value)) {
            return 'Laravel enum case value: ' . $value;
        }

        return 'Laravel enum case';
    }
}

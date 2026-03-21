<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Scip\Index;
use Scip\Occurrence;
use Scip\SymbolRole;
use WeakMap;

use function array_key_exists;
use function array_values;
use function iterator_to_array;
use function str_contains;
use function str_ends_with;
use function str_replace;

final class BaselineLookupCache
{
    /** @var ?WeakMap<Index, self> */
    private static ?WeakMap $instances = null;

    /**
     * @var array<string, list<array{symbol: string, startLine: ?int}>>
     */
    private array $definitionsByPath = [];

    /**
     * @var array<string, ?string>
     */
    private array $classSymbolCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $methodSymbolCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $propertySymbolCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $constantSymbolCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $enumCaseSymbolCache = [];

    private function __construct(Index $baselineIndex)
    {
        foreach ($baselineIndex->getDocuments() as $document) {
            $definitions = [];

            foreach ($document->getOccurrences() as $occurrence) {
                if (!$this->isDefinition($occurrence)) {
                    continue;
                }

                $range = array_values(iterator_to_array($occurrence->getRange(), false));
                $startLine = $range[0] ?? null;

                $definitions[] = [
                    'symbol' => $occurrence->getSymbol(),
                    'startLine' => is_int($startLine) ? $startLine : null,
                ];
            }

            $this->definitionsByPath[$document->getRelativePath()] = $definitions;
        }
    }

    public static function for(Index $baselineIndex): self
    {
        self::$instances ??= new WeakMap();

        if (!isset(self::$instances[$baselineIndex])) {
            self::$instances[$baselineIndex] = new self($baselineIndex);
        }

        return self::$instances[$baselineIndex];
    }

    public function resolveClassSymbol(string $relativePath, string $className, int $lineNumber): ?string
    {
        $cacheKey = $relativePath . "\x1F" . $className . "\x1F" . $lineNumber;

        if (array_key_exists($cacheKey, $this->classSymbolCache)) {
            return $this->classSymbolCache[$cacheKey];
        }

        $expectedLine = $lineNumber - 1;
        $pattern = str_replace('\\', '/', $className) . '#';
        $fallback = null;
        $exactFallback = null;

        foreach ($this->definitionsByPath[$relativePath] ?? [] as $definition) {
            $symbol = $definition['symbol'];

            if (!str_contains($symbol, $pattern)) {
                continue;
            }

            $fallback ??= $symbol;
            $isExactClassSymbol = str_ends_with($symbol, $pattern);
            $exactFallback ??= $isExactClassSymbol ? $symbol : null;

            if ($isExactClassSymbol && $definition['startLine'] === $expectedLine) {
                return $this->classSymbolCache[$cacheKey] = $symbol;
            }
        }

        return $this->classSymbolCache[$cacheKey] = $exactFallback ?? $fallback;
    }

    public function resolveMethodSymbol(string $relativePath, string $methodName, int $lineNumber): ?string
    {
        $cacheKey = $relativePath . "\x1F" . $methodName . "\x1F" . $lineNumber;

        if (array_key_exists($cacheKey, $this->methodSymbolCache)) {
            return $this->methodSymbolCache[$cacheKey];
        }

        $expectedLine = $lineNumber - 1;
        $pattern = '#' . $methodName . '().';
        $fallback = null;
        $exactFallback = null;

        foreach ($this->definitionsByPath[$relativePath] ?? [] as $definition) {
            $symbol = $definition['symbol'];

            if (!str_contains($symbol, $pattern)) {
                continue;
            }

            $fallback ??= $symbol;
            $isExactMethodSymbol = str_ends_with($symbol, $pattern);
            $exactFallback ??= $isExactMethodSymbol ? $symbol : null;

            if ($isExactMethodSymbol && $definition['startLine'] === $expectedLine) {
                return $this->methodSymbolCache[$cacheKey] = $symbol;
            }
        }

        return $this->methodSymbolCache[$cacheKey] = $exactFallback ?? $fallback;
    }

    public function resolvePropertySymbol(string $relativePath, string $className, string $propertyName): ?string
    {
        $cacheKey = $relativePath . "\x1F" . $className . "\x1F" . $propertyName;

        if (array_key_exists($cacheKey, $this->propertySymbolCache)) {
            return $this->propertySymbolCache[$cacheKey];
        }

        $pattern = str_replace('\\', '/', $className) . '#$' . $propertyName . '.';

        foreach ($this->definitionsByPath[$relativePath] ?? [] as $definition) {
            if (str_contains($definition['symbol'], $pattern)) {
                return $this->propertySymbolCache[$cacheKey] = $definition['symbol'];
            }
        }

        return $this->propertySymbolCache[$cacheKey] = null;
    }

    public function resolveConstantSymbol(string $relativePath, string $className, string $constantName): ?string
    {
        $cacheKey = $relativePath . "\x1F" . $className . "\x1F" . $constantName;

        if (array_key_exists($cacheKey, $this->constantSymbolCache)) {
            return $this->constantSymbolCache[$cacheKey];
        }

        $pattern = str_replace('\\', '/', $className) . '#' . $constantName . '.';

        foreach ($this->definitionsByPath[$relativePath] ?? [] as $definition) {
            if (str_contains($definition['symbol'], $pattern)) {
                return $this->constantSymbolCache[$cacheKey] = $definition['symbol'];
            }
        }

        return $this->constantSymbolCache[$cacheKey] = null;
    }

    public function resolveEnumCaseSymbol(string $relativePath, string $className, string $caseName): ?string
    {
        $cacheKey = $relativePath . "\x1F" . $className . "\x1F" . $caseName;

        if (array_key_exists($cacheKey, $this->enumCaseSymbolCache)) {
            return $this->enumCaseSymbolCache[$cacheKey];
        }

        $pattern = str_replace('\\', '/', $className) . '#' . $caseName . '.';

        foreach ($this->definitionsByPath[$relativePath] ?? [] as $definition) {
            if (str_contains($definition['symbol'], $pattern)) {
                return $this->enumCaseSymbolCache[$cacheKey] = $definition['symbol'];
            }
        }

        return $this->enumCaseSymbolCache[$cacheKey] = null;
    }

    private function isDefinition(Occurrence $occurrence): bool
    {
        return ($occurrence->getSymbolRoles() & SymbolRole::Definition) !== 0;
    }
}

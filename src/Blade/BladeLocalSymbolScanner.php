<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;
use PhpParser\Error;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function ksort;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

final class BladeLocalSymbolScanner
{
    private Parser $parser;
    private readonly BladeRuntimeCache $cache;

    public function __construct(?BladeRuntimeCache $cache = null)
    {
        $this->cache = $cache ?? BladeRuntimeCache::shared();
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @return list<BladeLocalSymbolDeclaration>
     */
    public function scanDeclarations(string $contents): array
    {
        return $this->cache->remember('blade-local-declarations', sha1($contents), function () use ($contents): array {
            $declarations = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '@', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                $matched = false;

                foreach (['props' => 'prop', 'aware' => 'aware'] as $directive => $kind) {
                    $declarationsForDirective = $this->matchDirectiveDeclarations($contents, $offset, $directive);

                    if ($declarationsForDirective === []) {
                        continue;
                    }

                    $declarationsForDirective = array_map(
                        static fn(BladeLocalSymbolDeclaration $declaration): BladeLocalSymbolDeclaration => new BladeLocalSymbolDeclaration(
                            kind: $kind,
                            name: $declaration->name,
                            symbol: str_replace(
                                'blade-' . $declaration->kind . '-',
                                'blade-' . $kind . '-',
                                $declaration->symbol,
                            ),
                            range: $declaration->range,
                            enclosingRange: $declaration->enclosingRange,
                        ),
                        $declarationsForDirective,
                    );

                    foreach ($declarationsForDirective as $declaration) {
                        $declarations[$this->declarationKey($declaration)] = $declaration;
                    }

                    $matched = true;
                    break;
                }

                $offset++;

                if ($matched) {
                    continue;
                }
            }

            $offset = 0;

            while (($offset = strpos($contents, '<', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                $declaration = $this->matchSlotDeclaration($contents, $offset);
                $offset++;

                if ($declaration === null) {
                    continue;
                }

                $declarations[$this->declarationKey($declaration)] = $declaration;
            }

            $filtered = $this->filterAmbiguousDeclarations(array_values($declarations));

            usort($filtered, static fn(
                BladeLocalSymbolDeclaration $left,
                BladeLocalSymbolDeclaration $right,
            ): int => strcmp(
                $left->symbol . ':' . implode(':', $left->range->toScipRange()),
                $right->symbol . ':' . implode(':', $right->range->toScipRange()),
            ));

            return $filtered;
        });
    }

    /**
     * @param list<BladeLocalSymbolDeclaration> $declarations
     * @return list<BladeLocalSymbolReference>
     */
    public function scanVariableReads(string $contents, array $declarations): array
    {
        return $this->scanVariableReadsByGroups($contents, [$declarations])[0] ?? [];
    }

    /**
     * @param list<list<BladeLocalSymbolDeclaration>> $declarationGroups
     * @return list<list<BladeLocalSymbolReference>>
     */
    public function scanVariableReadsByGroups(string $contents, array $declarationGroups): array
    {
        if ($declarationGroups === []) {
            return [];
        }

        $eligibleDeclarationsByGroup = [];
        $groupSignatures = [];
        $eligibleNames = [];

        foreach ($declarationGroups as $groupIndex => $declarations) {
            $groupSignatures[] = $this->declarationsSignature($declarations);
            $eligibleDeclarationsByGroup[$groupIndex] = $this->eligibleDeclarationsByName($declarations);

            foreach (array_keys($eligibleDeclarationsByGroup[$groupIndex]) as $name) {
                $eligibleNames[$name] = true;
            }
        }

        if ($eligibleNames === []) {
            return array_fill(0, count($declarationGroups), []);
        }

        $eligibleNames = array_keys($eligibleNames);
        sort($eligibleNames);

        /** @var list<list<BladeLocalSymbolReference>> $referencesByGroup */
        $referencesByGroup = $this->cache->remember(
            'blade-local-variable-read-groups',
            sha1($contents) . ':' . sha1(implode('||', $groupSignatures)),
            function () use ($contents, $declarationGroups, $eligibleDeclarationsByGroup, $eligibleNames): array {
                $pattern =
                    '/(?<![A-Za-z0-9_])\\$(?<name>'
                    . implode('|', array_map($this->regexEscape(...), $eligibleNames))
                    . ')(?![A-Za-z0-9_])/m';
                $matches = [];
                preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);
                $ignoredSpans = $this->ignoredSpans($contents);
                $referencesByGroup = array_fill(0, count($declarationGroups), []);

                foreach ($matches['name'] as [$name, $nameOffset]) {
                    if (
                        !is_string($name)
                        || !is_int($nameOffset)
                        || $this->isIgnoredOffset($ignoredSpans, $nameOffset)
                    ) {
                        continue;
                    }

                    foreach ($eligibleDeclarationsByGroup as $groupIndex => $declarationsByName) {
                        $declaration = $declarationsByName[$name] ?? null;

                        if (!$declaration instanceof BladeLocalSymbolDeclaration) {
                            continue;
                        }

                        $referencesByGroup[$groupIndex][$declaration->symbol . ':' . $nameOffset] =
                            new BladeLocalSymbolReference(
                                symbol: $declaration->symbol,
                                range: SourceRange::fromOffsets($contents, $nameOffset, $nameOffset + strlen($name)),
                            );
                    }
                }

                foreach ($referencesByGroup as $groupIndex => $references) {
                    ksort($references);
                    $referencesByGroup[$groupIndex] = array_values($references);
                }

                return $referencesByGroup;
            },
        );

        return $referencesByGroup;
    }

    /**
     * @return list<BladeLocalSymbolDeclaration>
     */
    private function matchDirectiveDeclarations(string $contents, int $directiveOffset, string $directive): array
    {
        $afterName = $directiveOffset + 1 + strlen($directive);

        if (substr($contents, $directiveOffset + 1, strlen($directive)) !== $directive) {
            return [];
        }

        $openParen = $this->skipWhitespace($contents, $afterName);

        if (($contents[$openParen] ?? null) !== '(') {
            return [];
        }

        $argument = $this->argumentSpan($contents, $openParen, 0);

        if ($argument === null) {
            return [];
        }

        [$startOffset, $endOffset] = $argument;
        $raw = trim(substr($contents, $startOffset, $endOffset - $startOffset));
        $containerRange = SourceRange::fromOffsets(
            $contents,
            $directiveOffset,
            $this->directiveContainerEnd($contents, $openParen),
        );

        return $this->arrayKeyDeclarations(
            contents: $contents,
            raw: $raw,
            rawStartOffset: $startOffset
            + $this->leadingWhitespaceOffset(substr($contents, $startOffset, $endOffset - $startOffset)),
            kind: $directive,
            containerRange: $containerRange,
        );
    }

    private function matchSlotDeclaration(string $contents, int $tagOffset): ?BladeLocalSymbolDeclaration
    {
        if (!str_starts_with(substr($contents, $tagOffset), '<x-slot')) {
            return null;
        }

        $tagEndOffset = $this->tagEndOffset($contents, $tagOffset);

        if ($tagEndOffset === null) {
            return null;
        }

        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);

        if (str_contains($tagContents, ':name=')) {
            return null;
        }

        $shorthand = $this->matchSlotShorthand($contents, $tagOffset, $tagEndOffset);

        if ($shorthand !== null) {
            return $shorthand;
        }

        return $this->matchSlotLonghand($contents, $tagOffset, $tagEndOffset);
    }

    private function matchSlotShorthand(
        string $contents,
        int $tagOffset,
        int $tagEndOffset,
    ): ?BladeLocalSymbolDeclaration {
        if (!str_starts_with(substr($contents, $tagOffset), '<x-slot:')) {
            return null;
        }

        $nameOffset = $tagOffset + strlen('<x-slot:');
        $nameEndOffset = $this->componentNameEnd($contents, $nameOffset);
        $name = substr($contents, $nameOffset, $nameEndOffset - $nameOffset);

        if ($name === '' || !$this->isLiteralBladeName($name)) {
            return null;
        }

        return new BladeLocalSymbolDeclaration(
            kind: 'slot',
            name: $name,
            symbol: $this->localSymbol('slot', $name),
            range: SourceRange::fromOffsets($contents, $nameOffset, $nameEndOffset),
            enclosingRange: SourceRange::fromOffsets(
                $contents,
                $tagOffset,
                $this->slotContainerEnd($contents, $tagEndOffset),
            ),
        );
    }

    private function matchSlotLonghand(
        string $contents,
        int $tagOffset,
        int $tagEndOffset,
    ): ?BladeLocalSymbolDeclaration {
        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);
        $matched = preg_match(
            '/\bname\s*=\s*(["\'])(?<name>[A-Za-z_][A-Za-z0-9_-]*)\1/',
            $tagContents,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched !== 1) {
            return null;
        }

        $name = $matches['name'][0] ?? null;
        $nameOffset = $matches['name'][1] ?? null;

        if (!is_string($name) || !is_int($nameOffset) || !$this->isLiteralBladeName($name)) {
            return null;
        }

        $absoluteOffset = $tagOffset + $nameOffset;

        return new BladeLocalSymbolDeclaration(
            kind: 'slot',
            name: $name,
            symbol: $this->localSymbol('slot', $name),
            range: SourceRange::fromOffsets($contents, $absoluteOffset, $absoluteOffset + strlen($name)),
            enclosingRange: SourceRange::fromOffsets(
                $contents,
                $tagOffset,
                $this->slotContainerEnd($contents, $tagEndOffset),
            ),
        );
    }

    /**
     * @return list<BladeLocalSymbolDeclaration>
     */
    private function arrayKeyDeclarations(
        string $contents,
        string $raw,
        int $rawStartOffset,
        string $kind,
        SourceRange $containerRange,
    ): array {
        try {
            $ast = $this->parser->parse('<?php return ' . $raw . ';');
        } catch (Error) {
            return [];
        }

        $statement = $ast[0] ?? null;

        if (!$statement instanceof Return_) {
            return [];
        }

        $array = $statement->expr ?? null;

        if (!$array instanceof Array_) {
            return [];
        }

        $offsetBase = $rawStartOffset - strlen('<?php return ');
        $declarations = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            $node = $item->key instanceof String_
                ? $item->key
                : ($item->key === null && $item->value instanceof String_ ? $item->value : null);

            if (!$node instanceof String_ || !$this->isLiteralBladeName($node->value)) {
                continue;
            }

            $startOffset = $offsetBase + $node->getStartFilePos() + 1;
            $endOffset = $offsetBase + $node->getEndFilePos();

            $declaration = new BladeLocalSymbolDeclaration(
                kind: $kind,
                name: $node->value,
                symbol: $this->localSymbol($kind, $node->value),
                range: SourceRange::fromOffsets($contents, $startOffset, $endOffset),
                enclosingRange: $containerRange,
            );

            $declarations[$this->declarationKey($declaration)] = $declaration;
        }

        return array_values($declarations);
    }

    /**
     * @param list<BladeLocalSymbolDeclaration> $declarations
     * @return list<BladeLocalSymbolDeclaration>
     */
    private function filterAmbiguousDeclarations(array $declarations): array
    {
        $counts = [];

        foreach ($declarations as $declaration) {
            $counts[$declaration->name] = ($counts[$declaration->name] ?? 0) + 1;
        }

        return array_values(array_filter(
            $declarations,
            static fn(BladeLocalSymbolDeclaration $declaration): bool => ($counts[$declaration->name] ?? 0) === 1,
        ));
    }

    private function localSymbol(string $kind, string $name): string
    {
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9_$+\-]+/', '-', $name) ?? $name);

        return 'local blade-' . $kind . '-' . $normalized;
    }

    private function declarationKey(BladeLocalSymbolDeclaration $declaration): string
    {
        return implode(':', [
            $declaration->kind,
            $declaration->name,
            ...array_map('strval', $declaration->range->toScipRange()),
        ]);
    }

    private function directiveContainerEnd(string $contents, int $openParenOffset): int
    {
        $depth = 0;
        $length = strlen($contents);

        for ($offset = $openParenOffset; $offset < $length; $offset++) {
            $char = $contents[$offset];

            if ($char === '\'' || $char === '"') {
                $offset = $this->skipStringLiteral($contents, $offset, $char);
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $offset + 1;
                }
            }
        }

        return $length;
    }

    private function slotContainerEnd(string $contents, int $tagEndOffset): int
    {
        $closeTagOffset = strpos($contents, '</x-slot>', $tagEndOffset + 1);

        return $closeTagOffset === false ? $tagEndOffset + 1 : $closeTagOffset + strlen('</x-slot>');
    }

    private function leadingWhitespaceOffset(string $contents): int
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if (!in_array($char, [' ', "\t", "\n", "\r"], true)) {
                return $offset;
            }

            $offset++;
        }

        return 0;
    }

    /**
     * @return ?array{int, int}
     */
    private function argumentSpan(string $contents, int $openParenOffset, int $targetArgument): ?array
    {
        $depth = 0;
        $argumentIndex = 0;
        $argumentStart = $openParenOffset + 1;
        $length = strlen($contents);

        for ($offset = $openParenOffset + 1; $offset < $length; $offset++) {
            $char = $contents[$offset];

            if ($char === '\'' || $char === '"') {
                $offset = $this->skipStringLiteral($contents, $offset, $char);
                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }

                if ($char === ')') {
                    return $argumentIndex === $targetArgument ? [$argumentStart, $offset] : null;
                }

                continue;
            }

            if ($char === ',' && $depth === 0) {
                if ($argumentIndex === $targetArgument) {
                    return [$argumentStart, $offset];
                }

                $argumentIndex++;
                $argumentStart = $offset + 1;
            }
        }

        return null;
    }

    private function skipStringLiteral(string $contents, int $offset, string $quote): int
    {
        $length = strlen($contents);

        for ($offset++; $offset < $length; $offset++) {
            if ($contents[$offset] === '\\') {
                $offset++;
                continue;
            }

            if ($contents[$offset] === $quote) {
                return $offset;
            }
        }

        return $length;
    }

    private function skipWhitespace(string $contents, int $offset): int
    {
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if (!in_array($char, [' ', "\t", "\n", "\r"], true)) {
                return $offset;
            }

            $offset++;
        }

        return $offset;
    }

    private function componentNameEnd(string $contents, int $offset): int
    {
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if (
                $char >= 'a' && $char <= 'z'
                || $char >= 'A' && $char <= 'Z'
                || $char >= '0' && $char <= '9'
                || $char === '.'
                || $char === '-'
                || $char === '_'
            ) {
                $offset++;
                continue;
            }

            break;
        }

        return $offset;
    }

    private function tagEndOffset(string $contents, int $offset): ?int
    {
        $length = strlen($contents);

        for ($offset++; $offset < $length; $offset++) {
            $char = $contents[$offset];

            if ($char === '\'' || $char === '"') {
                $offset = $this->skipStringLiteral($contents, $offset, $char);
                continue;
            }

            if ($char === '>') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * @return list<array{int, int}>
     */
    private function ignoredSpans(string $contents): array
    {
        return $this->cache->remember('blade-local-ignored-spans', sha1($contents), function () use ($contents): array {
            $spans = [];

            foreach ([['{{--', '--}}'], ['<!--', '-->']] as [$startToken, $endToken]) {
                $offset = 0;

                while (($start = strpos($contents, $startToken, $offset)) !== false) {
                    $end = strpos($contents, $endToken, $start + strlen($startToken));
                    $end = $end === false ? strlen($contents) : $end + strlen($endToken);
                    $spans[] = [$start, $end];
                    $offset = $end;
                }
            }

            usort($spans, static fn(array $left, array $right): int => $left[0] <=> $right[0]);

            return $spans;
        });
    }

    /**
     * @param list<BladeLocalSymbolDeclaration> $declarations
     */
    private function declarationsSignature(array $declarations): string
    {
        if ($declarations === []) {
            return '';
        }

        $signature = [];

        foreach ($declarations as $declaration) {
            $signature[] = implode(':', [
                $declaration->kind,
                $declaration->name,
                $declaration->symbol,
            ]);
        }

        sort($signature);

        return implode('|', $signature);
    }

    /**
     * @param list<BladeLocalSymbolDeclaration> $declarations
     * @return array<string, BladeLocalSymbolDeclaration>
     */
    private function eligibleDeclarationsByName(array $declarations): array
    {
        $declarationsByName = [];

        foreach ($declarations as $declaration) {
            $declarationsByName[$declaration->name][] = $declaration;
        }

        $eligible = [];

        foreach ($declarationsByName as $name => $declarationsForName) {
            if (count($declarationsForName) !== 1 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
                continue;
            }

            $eligible[$name] = $declarationsForName[0];
        }

        return $eligible;
    }

    /**
     * @param list<array{int, int}> $spans
     */
    private function isIgnoredOffset(array $spans, int $offset): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset < $start) {
                return false;
            }

            if ($offset < $end) {
                return true;
            }
        }

        return false;
    }

    private function isLiteralBladeName(string $name): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $name) === 1;
    }

    private function regexEscape(string $name): string
    {
        return preg_replace('/([\\\\.^$|?*+()[\]{}-])/', '\\\\$1', $name) ?? $name;
    }
}

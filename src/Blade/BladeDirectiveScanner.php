<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcspn;
use function stripcslashes;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function uasort;
use function usort;

final class BladeDirectiveScanner
{
    private readonly BladeRuntimeCache $cache;

    public function __construct(?BladeRuntimeCache $cache = null)
    {
        $this->cache = $cache ?? BladeRuntimeCache::shared();
    }

    /**
     * @var array<string, true>
     */
    private const BUILTIN_WIRE_EVENT_NAMES = [
        'bind' => true,
        'blur' => true,
        'change' => true,
        'click' => true,
        'focus' => true,
        'ignore' => true,
        'init' => true,
        'keydown' => true,
        'keypress' => true,
        'keyup' => true,
        'loading' => true,
        'model' => true,
        'offline' => true,
        'replace' => true,
        'show' => true,
        'sort' => true,
        'submit' => true,
        'target' => true,
        'text' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const DOM_EVENT_NAMES = [
        'blur' => true,
        'change' => true,
        'click' => true,
        'dblclick' => true,
        'focus' => true,
        'focusin' => true,
        'focusout' => true,
        'input' => true,
        'keydown' => true,
        'keypress' => true,
        'keyup' => true,
        'load' => true,
        'mousedown' => true,
        'mouseenter' => true,
        'mouseleave' => true,
        'mousemove' => true,
        'mouseout' => true,
        'mouseover' => true,
        'mouseup' => true,
        'resize' => true,
        'scroll' => true,
        'submit' => true,
    ];

    /**
     * @var array<string, array{domain: string, argument: int, literal_prefix?: string}>
     */
    private const DIRECTIVES = [
        'includeUnless' => ['domain' => 'view', 'argument' => 1],
        'includeWhen' => ['domain' => 'view', 'argument' => 1],
        'includeIf' => ['domain' => 'view', 'argument' => 0],
        'include' => ['domain' => 'view', 'argument' => 0],
        'extends' => ['domain' => 'view', 'argument' => 0],
        'livewire' => ['domain' => 'view', 'argument' => 0, 'literal_prefix' => 'livewire.'],
        'lang' => ['domain' => 'translation', 'argument' => 0],
        'error' => ['domain' => 'validation', 'argument' => 0],
    ];

    /**
     * @return list<BladeLiteralReference>
     */
    public function scan(string $contents, array $prefixedTagPrefixes = []): array
    {
        $cacheKey = sha1($contents) . ':' . implode("\0", $this->normalisePrefixedTagPrefixes($prefixedTagPrefixes));

        return $this->cache->remember('blade-directive-scan', $cacheKey, function () use (
            $contents,
            $prefixedTagPrefixes,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '@', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                $match = $this->matchDirective($contents, $offset);
                $offset++;

                if ($match === null) {
                    continue;
                }

                $matches[$this->matchKey($match)] = $match;
            }

            $offset = 0;

            while (($offset = strpos($contents, '<', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                $match = $this->matchComponentTag($contents, $offset, $prefixedTagPrefixes);
                $offset++;

                if ($match === null) {
                    continue;
                }

                $matches[$this->matchKey($match)] = $match;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLiteralReference>
     */
    public function scanViewReferences(string $contents, array $prefixedTagPrefixes = []): array
    {
        $cacheKey = sha1($contents) . ':' . implode("\0", $this->normalisePrefixedTagPrefixes($prefixedTagPrefixes));

        return $this->cache->remember('blade-view-references', $cacheKey, function () use (
            $contents,
            $prefixedTagPrefixes,
        ): array {
            return array_values(array_filter(
                $this->scan($contents, $prefixedTagPrefixes),
                static fn(BladeLiteralReference $reference): bool => $reference->domain === 'view',
            ));
        });
    }

    /**
     * @return list<BladeLiteralReference>
     */
    public function scanTranslationReferences(string $contents): array
    {
        return $this->cache->remember('blade-translation-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];

            foreach (array_filter(
                $this->scan($contents),
                static fn(BladeLiteralReference $reference): bool => $reference->domain === 'translation',
            ) as $reference) {
                $matches[$this->matchKey($reference)] = $reference;
            }

            foreach ($this->scanLiteralFunctionReferences($contents, [
                '__' => ['domain' => 'translation', 'directive' => 'translation-function'],
                'trans' => ['domain' => 'translation', 'directive' => 'translation-function'],
                'trans_choice' => ['domain' => 'translation', 'directive' => 'translation-function'],
            ]) as $reference) {
                $matches[$this->matchKey($reference)] = $reference;
            }

            foreach ($this->scanLiteralStaticMethodReferences($contents, [
                'Lang' => [
                    'get' => ['domain' => 'translation', 'directive' => 'translation-static-call'],
                ],
                'Illuminate\\Support\\Facades\\Lang' => [
                    'get' => ['domain' => 'translation', 'directive' => 'translation-static-call'],
                ],
            ]) as $reference) {
                $matches[$this->matchKey($reference)] = $reference;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLiteralReference>
     */
    public function scanRouteReferences(string $contents): array
    {
        return $this->cache->remember('blade-route-references', sha1($contents), function () use ($contents): array {
            $matches = [];

            foreach ($this->scanLiteralFunctionReferences($contents, [
                'route' => ['domain' => 'route', 'directive' => 'route-function'],
                'to_route' => ['domain' => 'route', 'directive' => 'route-function'],
            ]) as $reference) {
                $matches[$this->matchKey($reference)] = $reference;
            }

            foreach ($this->scanLiteralHelperMethodReferences(
                $contents,
                [
                    'request' => ['methods' => ['routeIs']],
                    'redirect' => ['methods' => ['route']],
                ],
                'route-method',
            ) as $reference) {
                $matches[$this->matchKey($reference)] = $reference;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLiteralReference>
     */
    public function scanValidationReferences(string $contents): array
    {
        return $this->cache->remember('blade-validation-references', sha1($contents), function () use (
            $contents,
        ): array {
            return array_values(array_filter(
                $this->scan($contents),
                static fn(BladeLiteralReference $reference): bool => $reference->domain === 'validation',
            ));
        });
    }

    /**
     * @return list<BladeUnsupportedSite>
     */
    public function scanUnsupportedSites(string $contents): array
    {
        return $this->cache->remember('blade-unsupported-sites', sha1($contents), function () use ($contents): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $matched = preg_match_all(
                '/<(?<tag>x-dynamic-component|livewire:dynamic-component)\b/',
                $contents,
                $groups,
                PREG_OFFSET_CAPTURE,
            );

            if ($matched !== false) {
                foreach ($groups['tag'] ?? [] as [$tag, $offset]) {
                    if (!is_string($tag) || !is_int($offset) || $this->isIgnoredOffset($ignoredSpans, $offset)) {
                        continue;
                    }

                    $matches[$tag . ':' . $offset] = new BladeUnsupportedSite(
                        range: SourceRange::fromOffsets($contents, $offset, $offset + strlen($tag)),
                        code: $tag === 'x-dynamic-component' ? 'blade.dynamic-component' : 'livewire.dynamic-component',
                        message: $tag === 'x-dynamic-component'
                            ? 'Unsupported dynamic Blade component target.'
                            : 'Unsupported dynamic Livewire component target.',
                        syntaxKind: 6,
                    );
                }
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeAuthorizationReference>
     */
    public function scanAuthorizationReferences(string $contents): array
    {
        return $this->cache->remember('blade-authorization-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '@', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                foreach (['elsecannot', 'elsecan', 'canany', 'cannot', 'can'] as $directive) {
                    $references = $this->matchAuthorizationDirective($contents, $offset, $directive);

                    if ($references === []) {
                        continue;
                    }

                    foreach ($references as $reference) {
                        $matches[$this->authorizationMatchKey($reference)] = $reference;
                    }

                    break;
                }

                $offset++;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLiteralReference>
     */
    public function scanLivewireDirectiveReferences(string $contents): array
    {
        return $this->cache->remember('blade-livewire-directive-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, 'wire:', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset += 5;
                    continue;
                }

                $match = $this->matchLivewireDirectiveReference($contents, $offset);
                $offset += 5;

                if ($match === null) {
                    continue;
                }

                $matches[$this->matchKey($match)] = $match;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLivewireEventReference>
     */
    public function scanLivewireEventReferences(string $contents): array
    {
        return $this->cache->remember('blade-livewire-event-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, 'wire:', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset += 5;
                    continue;
                }

                $match = $this->matchLivewireEventListenerReference($contents, $offset);
                $offset += 5;

                if ($match === null) {
                    continue;
                }

                $matches[$this->eventMatchKey($match)] = $match;
            }

            $offset = 0;

            while (($offset = strpos($contents, 'x-on:', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset += 5;
                    continue;
                }

                $match = $this->matchAlpineEventListenerReference($contents, $offset);
                $offset += 5;

                if ($match === null) {
                    continue;
                }

                $matches[$this->eventMatchKey($match)] = $match;
            }

            foreach ($this->matchBrowserDispatchReferences($contents, $ignoredSpans) as $match) {
                $matches[$this->eventMatchKey($match)] = $match;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLivewireNavigationReference>
     */
    public function scanLivewireNavigationReferences(string $contents): array
    {
        return $this->cache->remember('blade-livewire-navigation-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '<a', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset += 2;
                    continue;
                }

                $match = $this->matchLivewireNavigationReference($contents, $offset);
                $offset += 2;

                if ($match === null) {
                    continue;
                }

                $matches[$this->navigationMatchKey($match)] = $match;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLivewireSurfaceReference>
     */
    public function scanLivewireSurfaceReferences(string $contents): array
    {
        return $this->cache->remember('blade-livewire-surface-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '<', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                foreach ($this->matchLivewireSurfaceReferences($contents, $offset) as $match) {
                    $matches[$this->livewireSurfaceMatchKey($match)] = $match;
                }

                $offset++;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLivewireChildBindingReference>
     */
    public function scanLivewireChildBindingReferences(string $contents): array
    {
        return $this->cache->remember('blade-livewire-child-binding-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '<livewire:', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                foreach ($this->matchLivewireChildBindingReferences($contents, $offset) as $match) {
                    $key =
                        $match->childAlias
                        . ':'
                        . $match->kind
                        . ':'
                        . $match->parentProperty
                        . ':'
                        . implode(':', $match->parentRange->toScipRange())
                        . ':'
                        . ($match->childProperty ?? '');
                    $matches[$key] = $match;
                }

                $offset++;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    /**
     * @return list<BladeLayoutContractReference>
     */
    public function scanLayoutContractReferences(string $contents): array
    {
        return $this->cache->remember('blade-layout-contract-references', sha1($contents), function () use (
            $contents,
        ): array {
            $matches = [];
            $ignoredSpans = $this->ignoredSpans($contents);
            $offset = 0;

            while (($offset = strpos($contents, '@', $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset++;
                    continue;
                }

                foreach (['pushOnce', 'prepend', 'section', 'yield', 'stack', 'push'] as $directive) {
                    $match = $this->matchLayoutContractReference($contents, $offset, $directive);

                    if ($match === null) {
                        continue;
                    }

                    $matches[$this->layoutMatchKey($match)] = $match;

                    break;
                }

                $offset++;
            }

            ksort($matches);

            return array_values($matches);
        });
    }

    private function matchDirective(string $contents, int $directiveOffset): ?BladeLiteralReference
    {
        $names = array_keys(self::DIRECTIVES);
        uasort($names, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($names as $name) {
            $afterName = $directiveOffset + 1 + strlen($name);

            if (substr($contents, $directiveOffset + 1, strlen($name)) !== $name) {
                continue;
            }

            $openParen = $this->skipWhitespace($contents, $afterName);

            if (($contents[$openParen] ?? null) !== '(') {
                continue;
            }

            $config = self::DIRECTIVES[$name];
            $argument = $this->argumentSpan($contents, $openParen, $config['argument']);

            if ($argument === null) {
                return null;
            }

            [$startOffset, $endOffset] = $argument;
            $raw = trim(substr($contents, $startOffset, $endOffset - $startOffset));

            if (preg_match('/\A([\'"])(?<value>(?:\\\\.|(?!\1).)*)\1\z/s', $raw) !== 1) {
                return null;
            }

            $leadingWhitespace = strpos(substr($contents, $startOffset, $endOffset - $startOffset), $raw);
            $literalOffset = $startOffset + ($leadingWhitespace === false ? 0 : $leadingWhitespace);
            $contentOffset = $literalOffset + 1;
            $contentEndOffset = $literalOffset + strlen($raw) - 1;

            return new BladeLiteralReference(
                domain: $config['domain'],
                directive: $name,
                literal: ($config['literal_prefix'] ?? '') . $this->decodeLiteral($raw),
                range: SourceRange::fromOffsets($contents, $contentOffset, $contentEndOffset),
            );
        }

        return null;
    }

    private function matchLivewireDirectiveReference(string $contents, int $attributeOffset): ?BladeLiteralReference
    {
        $previous = $attributeOffset > 0 ? $contents[$attributeOffset - 1] : null;

        if (!$this->isAttributeBoundary($previous)) {
            return null;
        }

        $attributeEnd = $attributeOffset;
        $length = strlen($contents);

        while ($attributeEnd < $length && $this->isAttributeNameChar($contents[$attributeEnd])) {
            $attributeEnd++;
        }

        if ($attributeEnd <= $attributeOffset) {
            return null;
        }

        $attributeName = substr($contents, $attributeOffset, $attributeEnd - $attributeOffset);
        $directive = $this->normalizedLivewireDirective($attributeName);

        if ($directive === null) {
            return null;
        }

        $equalsOffset = $this->skipWhitespace($contents, $attributeEnd);

        if (($contents[$equalsOffset] ?? null) !== '=') {
            return null;
        }

        $valueOffset = $this->skipWhitespace($contents, $equalsOffset + 1);
        $quote = $contents[$valueOffset] ?? null;

        if ($quote !== '\'' && $quote !== '"') {
            return null;
        }

        $valueEndOffset = $this->skipStringLiteral($contents, $valueOffset, $quote);

        if ($valueEndOffset >= $length || ($contents[$valueEndOffset] ?? null) !== $quote) {
            return null;
        }

        $literal = trim(substr($contents, $valueOffset + 1, $valueEndOffset - $valueOffset - 1));

        if (!$this->isLiteralLivewireTarget($literal)) {
            return null;
        }

        return new BladeLiteralReference(
            domain: 'livewire',
            directive: $directive,
            literal: $literal,
            range: SourceRange::fromOffsets($contents, $valueOffset + 1, $valueEndOffset),
        );
    }

    private function matchLivewireEventListenerReference(
        string $contents,
        int $attributeOffset,
    ): ?BladeLivewireEventReference {
        $match = $this->matchEventListenerReference($contents, $attributeOffset, 'wire:', 'wire-listener');

        if ($match === null) {
            return null;
        }

        $builtinName = strtolower((string) preg_replace('/:.*/', '', $match['eventName']));

        return isset(self::BUILTIN_WIRE_EVENT_NAMES[$builtinName])
            ? null
            : $this->eventReferenceFromMatch($contents, $match, true);
    }

    private function matchAlpineEventListenerReference(
        string $contents,
        int $attributeOffset,
    ): ?BladeLivewireEventReference {
        $match = $this->matchEventListenerReference($contents, $attributeOffset, 'x-on:', 'alpine-listener');

        if ($match === null) {
            return null;
        }

        return isset(self::DOM_EVENT_NAMES[strtolower($match['eventName'])])
            ? null
            : $this->eventReferenceFromMatch($contents, $match, false);
    }

    private function matchComponentTag(
        string $contents,
        int $tagOffset,
        array $prefixedTagPrefixes,
    ): ?BladeLiteralReference {
        if (str_starts_with(substr($contents, $tagOffset), '<livewire:')) {
            return $this->componentTagReference(
                $contents,
                $tagOffset,
                tagPrefix: 'livewire:',
                directive: 'livewire-tag',
                literalPrefix: 'livewire.',
            );
        }

        if (str_starts_with(substr($contents, $tagOffset), '<x-')) {
            return $this->componentTagReference(
                $contents,
                $tagOffset,
                tagPrefix: 'x-',
                directive: 'blade-component-tag',
                literalPrefix: 'components.',
            );
        }

        usort($prefixedTagPrefixes, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($prefixedTagPrefixes as $prefix) {
            if ($prefix === '' || !str_starts_with(substr($contents, $tagOffset), '<' . $prefix . ':')) {
                continue;
            }

            return $this->componentTagReference(
                $contents,
                $tagOffset,
                tagPrefix: $prefix . ':',
                directive: 'prefixed-component-tag',
                literalPrefix: $prefix . ':',
            );
        }

        return null;
    }

    /**
     * @return ?array{
     *     source: string,
     *     eventName: string,
     *     eventStart: int,
     *     eventEnd: int,
     *     actionValue: string,
     *     actionStart: int,
     *     actionEnd: int
     * }
     */
    private function matchEventListenerReference(
        string $contents,
        int $attributeOffset,
        string $prefix,
        string $source,
    ): ?array {
        $previous = $attributeOffset > 0 ? $contents[$attributeOffset - 1] : null;

        if (!$this->isAttributeBoundary($previous)) {
            return null;
        }

        $attributeEnd = $attributeOffset;
        $length = strlen($contents);

        while ($attributeEnd < $length && $this->isAttributeNameChar($contents[$attributeEnd])) {
            $attributeEnd++;
        }

        if ($attributeEnd <= $attributeOffset) {
            return null;
        }

        $attributeName = substr($contents, $attributeOffset, $attributeEnd - $attributeOffset);

        if (!str_starts_with($attributeName, $prefix)) {
            return null;
        }

        $eventTail = substr($attributeName, strlen($prefix));
        $eventNameLength = strcspn($eventTail, '.');
        $eventName = substr($eventTail, 0, $eventNameLength);

        if ($eventName === '') {
            return null;
        }

        $literal = $this->quotedAttributeLiteral($contents, $attributeEnd);

        if ($literal === null) {
            return null;
        }

        $eventStart = $attributeOffset + strlen($prefix);

        return [
            'source' => $source,
            'eventName' => $eventName,
            'eventStart' => $eventStart,
            'eventEnd' => $eventStart + strlen($eventName),
            'actionValue' => trim($literal['value']),
            'actionStart' => $literal['start'],
            'actionEnd' => $literal['end'],
        ];
    }

    /**
     * @param array{
     *     source: string,
     *     eventName: string,
     *     eventStart: int,
     *     eventEnd: int,
     *     actionValue: string,
     *     actionStart: int,
     *     actionEnd: int
     * } $match
     */
    private function eventReferenceFromMatch(
        string $contents,
        array $match,
        bool $requireBareMethod,
    ): ?BladeLivewireEventReference {
        $methodName = null;
        $methodRange = null;

        if ($match['actionValue'] !== '' && $this->isBareIdentifier($match['actionValue'])) {
            $methodName = $match['actionValue'];
            $methodRange = SourceRange::fromOffsets($contents, $match['actionStart'], $match['actionEnd']);
        } elseif ($requireBareMethod) {
            return null;
        }

        return new BladeLivewireEventReference(
            source: $match['source'],
            kind: 'listener',
            eventName: $match['eventName'],
            eventRange: SourceRange::fromOffsets($contents, $match['eventStart'], $match['eventEnd']),
            methodName: $methodName,
            methodRange: $methodRange,
        );
    }

    /**
     * @param list<array{0: int, 1: int}> $ignoredSpans
     * @return list<BladeLivewireEventReference>
     */
    private function matchBrowserDispatchReferences(string $contents, array $ignoredSpans): array
    {
        $matches = [];

        foreach ([
            ['$dispatch(',           'alpine-dispatch',    false],
            ['$wire.$dispatch(',     'wire-dispatch',      false],
            ['$wire.$dispatchSelf(', 'wire-dispatch-self', false],
            ['$wire.$dispatchTo(',   'wire-dispatch-to',   true],
        ] as [$token, $source, $usesTarget]) {
            $offset = 0;

            while (($offset = strpos($contents, $token, $offset)) !== false) {
                if ($this->isIgnoredOffset($ignoredSpans, $offset)) {
                    $offset += strlen($token);
                    continue;
                }

                $match = $this->matchBrowserDispatchReference($contents, $offset, $token, $source, $usesTarget);
                $offset += strlen($token);

                if ($match === null) {
                    continue;
                }

                $matches[] = $match;
            }
        }

        return $matches;
    }

    private function matchBrowserDispatchReference(
        string $contents,
        int $offset,
        string $token,
        string $source,
        bool $usesTarget,
    ): ?BladeLivewireEventReference {
        $cursor = $this->skipWhitespace($contents, $offset + strlen($token));
        $firstArgument = $this->quotedLiteralAt($contents, $cursor);

        if ($firstArgument === null) {
            return null;
        }

        $event = $firstArgument;
        $cursor = $this->skipWhitespace($contents, $firstArgument['nextOffset']);

        if ($usesTarget) {
            if (($contents[$cursor] ?? null) !== ',') {
                return null;
            }

            $cursor = $this->skipWhitespace($contents, $cursor + 1);
            $event = $this->quotedLiteralAt($contents, $cursor);

            if ($event === null) {
                return null;
            }

            $cursor = $this->skipWhitespace($contents, $event['nextOffset']);
        }

        if (($contents[$cursor] ?? null) !== ')') {
            return null;
        }

        return new BladeLivewireEventReference(
            source: $source,
            kind: 'dispatch',
            eventName: $event['value'],
            eventRange: SourceRange::fromOffsets($contents, $event['start'], $event['end']),
        );
    }

    private function matchLivewireNavigationReference(
        string $contents,
        int $tagOffset,
    ): ?BladeLivewireNavigationReference {
        if (($contents[$tagOffset + 2] ?? ' ') !== ' ' && ($contents[$tagOffset + 2] ?? '>') !== '>') {
            return null;
        }

        $tagEndOffset = $this->tagEndOffset($contents, $tagOffset);

        if ($tagEndOffset === null) {
            return null;
        }

        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);
        $navigate = $this->attributePresenceMatch($tagContents, 'wire:navigate(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)');
        $current = $this->attributeLiteralMatch($tagContents, 'wire:current');
        $dataCurrent = $this->attributeLiteralMatch($tagContents, 'data-current');

        if ($navigate === null && $current === null && $dataCurrent === null) {
            return null;
        }

        $href = $this->attributeLiteralMatch($tagContents, 'href');

        if (
            $href === null
            || $href['value'] === ''
            || str_contains($href['value'], '://')
            || str_starts_with($href['value'], 'mailto:')
        ) {
            return null;
        }

        $targetKind = 'path';
        $target = trim($href['value']);
        $targetStart = $tagOffset + $href['start'];
        $targetEnd = $tagOffset + $href['end'];

        if (
            preg_match(
                '/\{\{\s*route\(\s*([\'"])(?<name>(?:\\\\.|(?!\1).)*)\1\s*\)\s*\}\}/s',
                $href['value'],
                $routeMatch,
                PREG_OFFSET_CAPTURE,
            ) === 1
        ) {
            $targetKind = 'route-name';
            $target = stripcslashes($routeMatch['name'][0]);
            $targetStart = $tagOffset + $href['start'] + $routeMatch['name'][1];
            $targetEnd = $targetStart + strlen($routeMatch['name'][0]);
        }

        if ($target === '' || $targetKind === 'path' && !str_starts_with($target, '/')) {
            return null;
        }

        $currentMatch = $current ?? $dataCurrent;
        $navigateModifiers = $navigate === null ? [] : $this->modifierSegments($navigate['suffix'] ?? '');

        return new BladeLivewireNavigationReference(
            targetKind: $targetKind,
            target: $target,
            targetRange: SourceRange::fromOffsets($contents, $targetStart, $targetEnd),
            navigateModifiers: $navigateModifiers,
            currentMode: $currentMatch['value'] ?? null,
            currentRange: $currentMatch === null
                ? null
                : SourceRange::fromOffsets(
                    $contents,
                    $tagOffset + $currentMatch['start'],
                    $tagOffset + $currentMatch['end'],
                ),
        );
    }

    /**
     * @return list<BladeLivewireChildBindingReference>
     */
    private function matchLivewireChildBindingReferences(string $contents, int $tagOffset): array
    {
        $tagNameMatch = preg_match(
            '/<livewire:(?<alias>[A-Za-z0-9_.:-]+)/',
            substr($contents, $tagOffset),
            $tagName,
            PREG_OFFSET_CAPTURE,
        );

        if ($tagNameMatch !== 1 || !isset($tagName['alias'][0]) || !is_string($tagName['alias'][0])) {
            return [];
        }

        $tagEndOffset = $this->tagEndOffset($contents, $tagOffset);

        if ($tagEndOffset === null) {
            return [];
        }

        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);
        $references = [];
        $model = $this->attributeLiteralMatch($tagContents, 'wire:model(?:\\.[A-Za-z0-9_-]+)*');

        if ($model !== null && $model['value'] !== '' && $this->isBareIdentifier($model['value'])) {
            $references[] = new BladeLivewireChildBindingReference(
                childAlias: str_replace(':', '.', $tagName['alias'][0]),
                kind: 'modelable',
                parentProperty: $model['value'],
                parentRange: SourceRange::fromOffsets(
                    $contents,
                    $tagOffset + $model['start'],
                    $tagOffset + $model['end'],
                ),
            );
        }

        if (
            preg_match_all(
                '/(?<=\\s):(?<child>[A-Za-z_][A-Za-z0-9_-]*)\\s*=\\s*(?<quote>[\'"])\\$(?<parent>[A-Za-z_][A-Za-z0-9_]*)(?P=quote)/',
                $tagContents,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) >= 1
        ) {
            foreach ($matches['child'] as $index => [$childProperty, $childOffset]) {
                $parentProperty = $matches['parent'][$index][0] ?? null;
                $parentOffset = $matches['parent'][$index][1] ?? null;

                if (
                    !is_string($childProperty)
                    || !is_int($childOffset)
                    || !is_string($parentProperty)
                    || !is_int($parentOffset)
                ) {
                    continue;
                }

                $references[] = new BladeLivewireChildBindingReference(
                    childAlias: str_replace(':', '.', $tagName['alias'][0]),
                    kind: 'reactive',
                    parentProperty: $parentProperty,
                    parentRange: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $parentOffset,
                        $tagOffset + $parentOffset + strlen($parentProperty),
                    ),
                    childProperty: $childProperty,
                    childRange: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $childOffset,
                        $tagOffset + $childOffset + strlen($childProperty),
                    ),
                );
            }
        }

        if (
            preg_match_all(
                '/(?<=\\s):\\$(?<property>[A-Za-z_][A-Za-z0-9_]*)\\b/',
                $tagContents,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) >= 1
        ) {
            foreach ($matches['property'] as [$property, $offset]) {
                if (!is_string($property) || !is_int($offset)) {
                    continue;
                }

                $references[] = new BladeLivewireChildBindingReference(
                    childAlias: str_replace(':', '.', $tagName['alias'][0]),
                    kind: 'reactive',
                    parentProperty: $property,
                    parentRange: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $offset,
                        $tagOffset + $offset + strlen($property),
                    ),
                    childProperty: $property,
                    childRange: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $offset,
                        $tagOffset + $offset + strlen($property),
                    ),
                );
            }
        }

        return $references;
    }

    /**
     * @return list<BladeLivewireSurfaceReference>
     */
    private function matchLivewireSurfaceReferences(string $contents, int $tagOffset): array
    {
        $next = $contents[$tagOffset + 1] ?? null;

        if ($next === null || !preg_match('/[A-Za-z]/', $next)) {
            return [];
        }

        $tagEndOffset = $this->tagEndOffset($contents, $tagOffset);

        if ($tagEndOffset === null) {
            return [];
        }

        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);
        $references = [];
        $poll = $this->attributeLiteralMatch(
            $tagContents,
            'wire:poll(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            allowMissingValue: true,
        );

        if ($poll !== null) {
            $methodName = null;
            $methodRange = null;

            if (($poll['value'] ?? '') !== '' && $this->isBareIdentifier($poll['value'])) {
                $methodName = $poll['value'];
                $methodRange = SourceRange::fromOffsets(
                    $contents,
                    $tagOffset + $poll['start'],
                    $tagOffset + $poll['end'],
                );
            }

            $references[] = new BladeLivewireSurfaceReference(
                kind: 'poll',
                range: $methodRange ?? SourceRange::fromOffsets(
                    $contents,
                    $tagOffset + $poll['attributeStart'],
                    $tagOffset + $poll['attributeEnd'],
                ),
                modifiers: $this->modifierSegments($poll['suffix'] ?? ''),
                methodName: $methodName,
                methodRange: $methodRange,
            );
        }

        foreach ([
            'stream' => 'wire:stream(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'ref' => 'wire:ref(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
        ] as $kind => $pattern) {
            $match = $this->attributeLiteralMatch($tagContents, $pattern);

            if ($match === null || $match['value'] === '') {
                continue;
            }

            $references[] = new BladeLivewireSurfaceReference(
                kind: $kind,
                name: $match['value'],
                range: SourceRange::fromOffsets($contents, $tagOffset + $match['start'], $tagOffset + $match['end']),
                modifiers: $this->modifierSegments($match['suffix'] ?? ''),
            );
        }

        if ($this->hasLiteralAttributeValue($tagContents, 'type', 'file')) {
            $upload = $this->attributeLiteralMatch($tagContents, 'wire:model(?:\\.[A-Za-z0-9_-]+)*');

            if ($upload !== null && $upload['value'] !== '' && $this->isLiteralLivewireTarget($upload['value'])) {
                $references[] = new BladeLivewireSurfaceReference(
                    kind: 'upload',
                    name: $upload['value'],
                    range: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $upload['start'],
                        $tagOffset + $upload['end'],
                    ),
                );
            }
        }

        foreach ([
            'text' => 'wire:text(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'show' => 'wire:show(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'bind' => 'wire:bind(?::[A-Za-z0-9_-]+)?(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
        ] as $kind => $pattern) {
            $match = $this->attributeLiteralMatch($tagContents, $pattern);

            if ($match === null || !$this->isBareIdentifier($match['value'])) {
                continue;
            }

            $references[] = new BladeLivewireSurfaceReference(
                kind: $kind,
                range: SourceRange::fromOffsets($contents, $tagOffset + $match['start'], $tagOffset + $match['end']),
                name: $match['value'],
                modifiers: $this->modifierSegments($match['suffix'] ?? ''),
            );
        }

        foreach ([
            'init' => 'wire:init(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'sort' => 'wire:sort(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
        ] as $kind => $pattern) {
            $match = $this->attributeLiteralMatch($tagContents, $pattern);

            if ($match === null || !$this->isBareIdentifier($match['value'])) {
                continue;
            }

            $range = SourceRange::fromOffsets($contents, $tagOffset + $match['start'], $tagOffset + $match['end']);

            $references[] = new BladeLivewireSurfaceReference(
                kind: $kind,
                range: $range,
                methodName: $match['value'],
                methodRange: $range,
                modifiers: $this->modifierSegments($match['suffix'] ?? ''),
            );
        }

        $loading = $this->attributePresenceMatch($tagContents, 'wire:loading(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)');

        if ($loading !== null) {
            $loadingRange = SourceRange::fromOffsets(
                $contents,
                $tagOffset + $loading['attributeStart'],
                $tagOffset + $loading['attributeEnd'],
            );

            $references[] = new BladeLivewireSurfaceReference(
                kind: 'ui-directive',
                range: $loadingRange,
                name: 'loading',
                modifiers: $this->modifierSegments($loading['suffix'] ?? ''),
            );

            $target = $this->attributeLiteralMatch($tagContents, 'wire:target');

            if ($target !== null && $target['value'] !== '' && $this->isBareIdentifier($target['value'])) {
                $references[] = new BladeLivewireSurfaceReference(
                    kind: 'loading-target',
                    range: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $target['start'],
                        $tagOffset + $target['end'],
                    ),
                    targetName: $target['value'],
                    targetRange: SourceRange::fromOffsets(
                        $contents,
                        $tagOffset + $target['start'],
                        $tagOffset + $target['end'],
                    ),
                    modifiers: $this->modifierSegments($loading['suffix'] ?? ''),
                );
            }
        }

        foreach ([
            'ignore' => 'wire:ignore(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'replace' => 'wire:replace(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
            'offline' => 'wire:offline(?<suffix>(?:\\.[A-Za-z0-9_-]+)*)',
        ] as $name => $pattern) {
            $match = $this->attributePresenceMatch($tagContents, $pattern);

            if ($match === null) {
                continue;
            }

            $references[] = new BladeLivewireSurfaceReference(
                kind: 'ui-directive',
                range: SourceRange::fromOffsets(
                    $contents,
                    $tagOffset + $match['attributeStart'],
                    $tagOffset + $match['attributeEnd'],
                ),
                name: $name,
                modifiers: $this->modifierSegments($match['suffix'] ?? ''),
            );
        }

        return $references;
    }

    private function matchLayoutContractReference(
        string $contents,
        int $directiveOffset,
        string $directive,
    ): ?BladeLayoutContractReference {
        $afterName = $directiveOffset + 1 + strlen($directive);

        if (substr($contents, $directiveOffset + 1, strlen($directive)) !== $directive) {
            return null;
        }

        $openParen = $this->skipWhitespace($contents, $afterName);

        if (($contents[$openParen] ?? null) !== '(') {
            return null;
        }

        $argument = $this->argumentSpan($contents, $openParen, 0);

        if ($argument === null) {
            return null;
        }

        $literal = $this->quotedDirectiveLiteral($contents, $argument[0], $argument[1]);

        if ($literal === null || $literal['value'] === '') {
            return null;
        }

        return new BladeLayoutContractReference(
            family: in_array($directive, ['yield', 'section'], true) ? 'section' : 'stack',
            kind: match ($directive) {
                'yield', 'stack' => 'consume',
                default => 'provide',
            },
            name: $literal['value'],
            range: $literal['range'],
        );
    }

    private function componentTagReference(
        string $contents,
        int $tagOffset,
        string $tagPrefix,
        string $directive,
        string $literalPrefix,
    ): ?BladeLiteralReference {
        $nameOffset = $tagOffset + 1 + strlen($tagPrefix);
        $nameEndOffset = $this->componentNameEnd($contents, $nameOffset);

        if ($nameEndOffset <= $nameOffset) {
            return null;
        }

        $component = substr($contents, $nameOffset, $nameEndOffset - $nameOffset);

        if ($component === '' || $component === 'dynamic-component' || $component === 'slot') {
            return null;
        }

        $tagEndOffset = $this->tagEndOffset($contents, $tagOffset);

        if ($tagEndOffset === null) {
            return null;
        }

        $tagContents = substr($contents, $tagOffset, $tagEndOffset - $tagOffset + 1);

        if (str_contains($tagContents, ':is=') || str_contains($tagContents, ':component=')) {
            return null;
        }

        return new BladeLiteralReference(
            domain: 'view',
            directive: $directive,
            literal: $literalPrefix . $component,
            range: SourceRange::fromOffsets($contents, $nameOffset, $nameEndOffset),
        );
    }

    /**
     * @return list<BladeAuthorizationReference>
     */
    private function matchAuthorizationDirective(string $contents, int $directiveOffset, string $directive): array
    {
        $afterName = $directiveOffset + 1 + strlen($directive);

        if (substr($contents, $directiveOffset + 1, strlen($directive)) !== $directive) {
            return [];
        }

        $openParen = $this->skipWhitespace($contents, $afterName);

        if (($contents[$openParen] ?? null) !== '(') {
            return [];
        }

        $abilitySpan = $this->argumentSpan($contents, $openParen, 0);

        if ($abilitySpan === null) {
            return [];
        }

        $targetSpan = $this->argumentSpan($contents, $openParen, 1);
        $target = $targetSpan === null ? null : $this->literalClassFetch($contents, $targetSpan[0], $targetSpan[1]);

        if ($directive === 'canany') {
            return $this->abilityListReferences(
                $contents,
                $directive,
                $abilitySpan[0],
                $abilitySpan[1],
                $target['class'] ?? null,
                $target['range'] ?? null,
            );
        }

        $literal = $this->quotedDirectiveLiteral($contents, $abilitySpan[0], $abilitySpan[1]);

        if ($literal === null) {
            return [];
        }

        return [
            new BladeAuthorizationReference(
                directive: $directive,
                ability: $literal['value'],
                abilityRange: $literal['range'],
                targetClassName: $target['class'] ?? null,
                targetClassRange: $target['range'] ?? null,
            ),
        ];
    }

    /**
     * @return list<BladeAuthorizationReference>
     */
    private function abilityListReferences(
        string $contents,
        string $directive,
        int $startOffset,
        int $endOffset,
        ?string $targetClassName,
        ?SourceRange $targetClassRange,
    ): array {
        $raw = trim(substr($contents, $startOffset, $endOffset - $startOffset));

        if (!str_starts_with($raw, '[') || !str_contains($raw, ']') || str_contains($raw, '$')) {
            return [];
        }

        $trimmedStart =
            $startOffset + $this->leadingWhitespaceOffset(substr($contents, $startOffset, $endOffset - $startOffset));
        $matches = [];
        preg_match_all('/([\'"])(?<value>(?:\\\\.|(?!\1).)*)\1/s', $raw, $matches, PREG_OFFSET_CAPTURE);
        $references = [];

        foreach ($matches['value'] ?? [] as [$value, $offset]) {
            if (!is_string($value) || !is_int($offset)) {
                continue;
            }

            $valueStart = $trimmedStart + $offset;
            $valueEnd = $valueStart + strlen($value);
            $references[] = new BladeAuthorizationReference(
                directive: $directive,
                ability: stripcslashes($value),
                abilityRange: SourceRange::fromOffsets($contents, $valueStart, $valueEnd),
                targetClassName: $targetClassName,
                targetClassRange: $targetClassRange,
            );
        }

        return $references;
    }

    /**
     * @return ?array{value: string, range: SourceRange}
     */
    private function quotedDirectiveLiteral(string $contents, int $startOffset, int $endOffset): ?array
    {
        $slice = substr($contents, $startOffset, $endOffset - $startOffset);
        $raw = trim($slice);

        if (preg_match('/\A([\'"])(?<value>(?:\\\\.|(?!\1).)*)\1\z/s', $raw, $matches) !== 1) {
            return null;
        }

        $leadingWhitespace = strpos($slice, $raw);
        $literalOffset = $startOffset + ($leadingWhitespace === false ? 0 : $leadingWhitespace);

        return [
            'value' => $this->decodeLiteral($raw),
            'range' => SourceRange::fromOffsets($contents, $literalOffset + 1, $literalOffset + strlen($raw) - 1),
        ];
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function literalClassFetch(string $contents, int $startOffset, int $endOffset): ?array
    {
        $slice = substr($contents, $startOffset, $endOffset - $startOffset);
        $raw = trim($slice);

        if (preg_match('/\A(?<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::class\z/', $raw, $matches) !== 1) {
            return null;
        }

        $leadingWhitespace = strpos($slice, $raw);
        $literalOffset = $startOffset + ($leadingWhitespace === false ? 0 : $leadingWhitespace);

        return [
            'class' => ltrim($matches['class'], '\\'),
            'range' => SourceRange::fromOffsets($contents, $literalOffset, $literalOffset + strlen($raw)),
        ];
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

    private function isAttributeBoundary(?string $char): bool
    {
        return $char === null || $char === '<' || $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }

    private function isAttributeNameChar(string $char): bool
    {
        return (
            $char >= 'a'
            && $char <= 'z'
            || $char >= 'A'
            && $char <= 'Z'
            || $char >= '0'
            && $char <= '9'
            || $char === ':'
            || $char === '.'
            || $char === '-'
            || $char === '_'
        );
    }

    private function normalizedLivewireDirective(string $attributeName): ?string
    {
        $baseName = strtolower(preg_replace('/\..*/', '', $attributeName) ?? $attributeName);

        return match ($baseName) {
            'wire:model' => 'wire-model',
            'wire:submit' => 'wire-submit',
            'wire:click' => 'wire-click',
            default => null,
        };
    }

    /**
     * @return ?array{attributeStart: int, attributeEnd: int, suffix?: string}
     */
    private function attributePresenceMatch(string $tagContents, string $pattern): ?array
    {
        if (preg_match('/\b(?<attribute>' . $pattern . ')\b/', $tagContents, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $attribute = $matches['attribute'][0] ?? null;
        $offset = $matches['attribute'][1] ?? null;

        if (!is_string($attribute) || !is_int($offset)) {
            return null;
        }

        $payload = [
            'attributeStart' => $offset,
            'attributeEnd' => $offset + strlen($attribute),
        ];

        if (isset($matches['suffix'][0]) && is_string($matches['suffix'][0])) {
            $payload['suffix'] = $matches['suffix'][0];
        }

        return $payload;
    }

    /**
     * @return ?array{value: string, start: int, end: int, attributeStart: int, attributeEnd: int, suffix?: string}
     */
    private function attributeLiteralMatch(
        string $tagContents,
        string $pattern,
        bool $allowMissingValue = false,
    ): ?array {
        if (
            preg_match(
                '/\b(?<attribute>' . $pattern . ')\b(?:\s*=\s*(?<quote>[\'"])(?<value>.*?)(?P=quote))?/s',
                $tagContents,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) !== 1
        ) {
            return null;
        }

        $attribute = $matches['attribute'][0] ?? null;
        $attributeOffset = $matches['attribute'][1] ?? null;

        if (!is_string($attribute) || !is_int($attributeOffset)) {
            return null;
        }

        $value = $matches['value'][0] ?? '';
        $valueOffset = $matches['value'][1] ?? null;

        if (!is_string($value)) {
            return null;
        }

        if (!$allowMissingValue && !is_int($valueOffset)) {
            return null;
        }

        $payload = [
            'value' => trim($value),
            'start' => is_int($valueOffset) ? $valueOffset : $attributeOffset,
            'end' => is_int($valueOffset) ? $valueOffset + strlen($value) : $attributeOffset + strlen($attribute),
            'attributeStart' => $attributeOffset,
            'attributeEnd' => $attributeOffset + strlen($attribute),
        ];

        if (isset($matches['suffix'][0]) && is_string($matches['suffix'][0])) {
            $payload['suffix'] = $matches['suffix'][0];
        }

        return $payload;
    }

    /**
     * @return list<array{value: string, start: int, end: int, attributeStart: int, attributeEnd: int, property?: string, suffix?: string}>
     */
    private function attributeLiteralMatches(
        string $tagContents,
        string $pattern,
        bool $allowMissingValue = false,
    ): array {
        $matched = preg_match_all(
            '/\b(?<attribute>' . $pattern . ')\b(?:\s*=\s*(?<quote>[\'"])(?<value>.*?)(?P=quote))?/s',
            $tagContents,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if (!is_int($matched) || $matched < 1) {
            return [];
        }

        $results = [];

        foreach ($matches['attribute'] as $index => [$attribute, $attributeOffset]) {
            if (!is_string($attribute) || !is_int($attributeOffset)) {
                continue;
            }

            $value = $matches['value'][$index][0] ?? '';
            $valueOffset = $matches['value'][$index][1] ?? null;

            if (!is_string($value)) {
                continue;
            }

            if (!$allowMissingValue && !is_int($valueOffset)) {
                continue;
            }

            $payload = [
                'value' => trim($value),
                'start' => is_int($valueOffset) ? $valueOffset : $attributeOffset,
                'end' => is_int($valueOffset) ? $valueOffset + strlen($value) : $attributeOffset + strlen($attribute),
                'attributeStart' => $attributeOffset,
                'attributeEnd' => $attributeOffset + strlen($attribute),
            ];

            if (isset($matches['property'][$index][0]) && is_string($matches['property'][$index][0])) {
                $payload['property'] = $matches['property'][$index][0];
            }

            if (isset($matches['suffix'][$index][0]) && is_string($matches['suffix'][$index][0])) {
                $payload['suffix'] = $matches['suffix'][$index][0];
            }

            $results[] = $payload;
        }

        return $results;
    }

    private function hasLiteralAttributeValue(string $tagContents, string $attributeName, string $expectedValue): bool
    {
        $match = $this->attributeLiteralMatch($tagContents, preg_quote($attributeName, '/'));

        return $match !== null && strtolower($match['value']) === strtolower($expectedValue);
    }

    /**
     * @return list<string>
     */
    private function modifierSegments(string $suffix): array
    {
        if ($suffix === '') {
            return [];
        }

        return array_values(array_filter(explode('.', ltrim($suffix, '.'))));
    }

    private function isLiteralLivewireTarget(string $literal): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $literal) === 1;
    }

    private function isBareIdentifier(string $literal): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $literal) === 1;
    }

    /**
     * @return ?array{value: string, start: int, end: int}
     */
    private function quotedAttributeLiteral(string $contents, int $attributeEnd): ?array
    {
        $equalsOffset = $this->skipWhitespace($contents, $attributeEnd);

        if (($contents[$equalsOffset] ?? null) !== '=') {
            return null;
        }

        $valueOffset = $this->skipWhitespace($contents, $equalsOffset + 1);
        $literal = $this->quotedLiteralAt($contents, $valueOffset);

        if ($literal === null) {
            return null;
        }

        return [
            'value' => trim($literal['value']),
            'start' => $literal['start'],
            'end' => $literal['end'],
        ];
    }

    /**
     * @return ?array{value: string, start: int, end: int, nextOffset: int}
     */
    private function quotedLiteralAt(string $contents, int $offset): ?array
    {
        $quote = $contents[$offset] ?? null;

        if ($quote !== '\'' && $quote !== '"') {
            return null;
        }

        $endOffset = $this->skipStringLiteral($contents, $offset, $quote);

        if (($contents[$endOffset] ?? null) !== $quote) {
            return null;
        }

        return [
            'value' => trim(substr($contents, $offset + 1, $endOffset - $offset - 1)),
            'start' => $offset + 1,
            'end' => $endOffset,
            'nextOffset' => $endOffset + 1,
        ];
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

            if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
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
        return $this->cache->remember('blade-ignored-spans', sha1($contents), function () use ($contents): array {
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
     * @param array<string, array{domain: string, directive: string}> $functions
     * @return list<BladeLiteralReference>
     */
    private function scanLiteralFunctionReferences(string $contents, array $functions): array
    {
        if ($functions === []) {
            return [];
        }

        $matches = [];
        $ignoredSpans = $this->ignoredSpans($contents);
        $pattern =
            '/(?<![A-Za-z0-9_\\\\])(?<function>'
            . implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), array_keys($functions)))
            . ')\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?(?<quote>[\'"])(?<literal>(?:\\\\.|(?!\k<quote>).)*)\k<quote>/si';

        if (preg_match_all($pattern, $contents, $captures, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== false) {
            foreach ($captures as $capture) {
                $functionName = strtolower($capture['function'][0] ?? '');
                $functionOffset = $capture['function'][1] ?? null;
                $literal = $capture['literal'][0] ?? null;
                $literalOffset = $capture['literal'][1] ?? null;

                if (
                    $functionName === ''
                    || !isset($functions[$functionName])
                    || !is_int($functionOffset)
                    || !is_string($literal)
                    || !is_int($literalOffset)
                    || $this->isIgnoredOffset($ignoredSpans, $functionOffset)
                    || $this->hasMethodCallPrefix($contents, $functionOffset)
                ) {
                    continue;
                }

                $matches[] = new BladeLiteralReference(
                    domain: $functions[$functionName]['domain'],
                    directive: $functions[$functionName]['directive'],
                    literal: stripcslashes($literal),
                    range: SourceRange::fromOffsets($contents, $literalOffset, $literalOffset + strlen($literal)),
                );
            }
        }

        return $matches;
    }

    private function scanLiteralStaticMethodReferences(string $contents, array $staticMethods): array
    {
        if ($staticMethods === []) {
            return [];
        }

        $normalizedCalls = [];
        $classNames = [];
        $methodNames = [];

        foreach ($staticMethods as $className => $methods) {
            $normalizedClassName = ltrim(strtolower($className), '\\');
            $classNames[$normalizedClassName] = true;

            foreach ($methods as $methodName => $config) {
                $normalizedMethodName = strtolower($methodName);
                $normalizedCalls[$normalizedClassName . '::' . $normalizedMethodName] = $config;
                $methodNames[$normalizedMethodName] = true;
            }
        }

        if ($normalizedCalls === []) {
            return [];
        }

        $matches = [];
        $ignoredSpans = $this->ignoredSpans($contents);
        $classPattern = implode('|', array_map(static fn(string $name): string => preg_quote(
            $name,
            '/',
        ), array_keys($classNames)));
        $methodPattern = implode('|', array_map(static fn(string $name): string => preg_quote(
            $name,
            '/',
        ), array_keys($methodNames)));
        $pattern =
            '/(?<![A-Za-z0-9_\\\\])(?<class>\\\\?(?:'
            . $classPattern
            . '))\s*::\s*(?<method>'
            . $methodPattern
            . ')\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?(?<quote>[\'"])(?<literal>(?:\\\\.|(?!\k<quote>).)*)\k<quote>/si';

        if (preg_match_all($pattern, $contents, $captures, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($captures as $capture) {
            $className = $capture['class'][0] ?? null;
            $classOffset = $capture['class'][1] ?? null;
            $methodName = $capture['method'][0] ?? null;
            $literal = $capture['literal'][0] ?? null;
            $literalOffset = $capture['literal'][1] ?? null;

            if (
                !is_string($className)
                || !is_int($classOffset)
                || !is_string($methodName)
                || !is_string($literal)
                || !is_int($literalOffset)
                || $this->isIgnoredOffset($ignoredSpans, $classOffset)
            ) {
                continue;
            }

            $normalizedCall = ltrim(strtolower($className), '\\') . '::' . strtolower($methodName);
            $config = $normalizedCalls[$normalizedCall] ?? null;

            if (!is_array($config)) {
                continue;
            }

            $matches[] = new BladeLiteralReference(
                domain: $config['domain'],
                directive: $config['directive'],
                literal: stripcslashes($literal),
                range: SourceRange::fromOffsets($contents, $literalOffset, $literalOffset + strlen($literal)),
            );
        }

        return $matches;
    }

    private function scanLiteralHelperMethodReferences(string $contents, array $helperMethods, string $directive): array
    {
        if ($helperMethods === []) {
            return [];
        }

        $matches = [];
        $ignoredSpans = $this->ignoredSpans($contents);

        foreach ($helperMethods as $helper => $config) {
            $methodPattern = implode('|', array_map(static fn(string $name): string => preg_quote(
                $name,
                '/',
            ), $config['methods']));

            if ($methodPattern === '') {
                continue;
            }

            $pattern =
                '/(?<![A-Za-z0-9_\\\\])'
                . preg_quote($helper, '/')
                . '\s*\(\s*\)\s*->\s*(?<method>'
                . $methodPattern
                . ')\s*\(\s*(?:[A-Za-z_][A-Za-z0-9_]*\s*:\s*)?(?<quote>[\'"])(?<literal>(?:\\\\.|(?!\k<quote>).)*)\k<quote>/si';

            if (preg_match_all($pattern, $contents, $captures, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($captures as $capture) {
                $helperOffset = $capture[0][1] ?? null;
                $literal = $capture['literal'][0] ?? null;
                $literalOffset = $capture['literal'][1] ?? null;

                if (
                    !is_int($helperOffset)
                    || !is_string($literal)
                    || !is_int($literalOffset)
                    || $this->isIgnoredOffset($ignoredSpans, $helperOffset)
                ) {
                    continue;
                }

                $matches[] = new BladeLiteralReference(
                    domain: 'route',
                    directive: $directive,
                    literal: stripcslashes($literal),
                    range: SourceRange::fromOffsets($contents, $literalOffset, $literalOffset + strlen($literal)),
                );
            }
        }

        return $matches;
    }

    private function hasMethodCallPrefix(string $contents, int $offset): bool
    {
        if ($offset < 2) {
            return false;
        }

        $prefix = substr($contents, $offset - 2, 2);

        return $prefix === '->' || $prefix === '::';
    }

    private function eventMatchKey(BladeLivewireEventReference $reference): string
    {
        return implode(':', [
            $reference->source,
            $reference->kind,
            $reference->eventRange->startLine,
            $reference->eventRange->startColumn,
            $reference->eventRange->endLine,
            $reference->eventRange->endColumn,
            $reference->eventName,
            $reference->methodName ?? '',
        ]);
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

    private function matchKey(BladeLiteralReference $match): string
    {
        return implode(':', [
            $match->domain,
            $match->directive,
            $match->literal,
            (string) $match->range->startLine,
            (string) $match->range->startColumn,
            (string) $match->range->endLine,
            (string) $match->range->endColumn,
        ]);
    }

    /**
     * @param list<string> $prefixedTagPrefixes
     * @return list<string>
     */
    private function normalisePrefixedTagPrefixes(array $prefixedTagPrefixes): array
    {
        $prefixedTagPrefixes = array_values(array_filter(
            array_map(static fn(mixed $prefix): string => is_string($prefix) ? $prefix : '', $prefixedTagPrefixes),
            static fn(string $prefix): bool => $prefix !== '',
        ));

        sort($prefixedTagPrefixes);

        return $prefixedTagPrefixes;
    }

    private function decodeLiteral(string $literal): string
    {
        $quote = $literal[0];
        $contents = substr($literal, 1, -1);

        if ($quote === '\'') {
            return str_replace(['\\\\', '\\\''], ['\\', '\''], $contents);
        }

        return stripcslashes($contents);
    }

    private function leadingWhitespaceOffset(string $contents): int
    {
        return strlen($contents) - strlen(ltrim($contents));
    }

    private function authorizationMatchKey(BladeAuthorizationReference $reference): string
    {
        return implode(':', [
            $reference->directive,
            $reference->ability,
            (string) $reference->abilityRange->startLine,
            (string) $reference->abilityRange->startColumn,
            $reference->targetClassName ?? '',
        ]);
    }

    private function navigationMatchKey(BladeLivewireNavigationReference $reference): string
    {
        return implode(':', [
            $reference->targetKind,
            $reference->target,
            (string) $reference->targetRange->startLine,
            (string) $reference->targetRange->startColumn,
            implode(',', $reference->navigateModifiers),
            $reference->currentMode ?? '',
        ]);
    }

    private function livewireSurfaceMatchKey(BladeLivewireSurfaceReference $reference): string
    {
        return implode(':', [
            $reference->kind,
            $reference->name ?? '',
            $reference->methodName ?? '',
            (string) $reference->range->startLine,
            (string) $reference->range->startColumn,
            implode(',', $reference->modifiers),
        ]);
    }

    private function layoutMatchKey(BladeLayoutContractReference $reference): string
    {
        return implode(':', [
            $reference->family,
            $reference->kind,
            $reference->name,
            (string) $reference->range->startLine,
            (string) $reference->range->startColumn,
        ]);
    }
}

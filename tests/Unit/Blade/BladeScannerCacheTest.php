<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Blade;

use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeLocalSymbolDeclaration;
use Bnomei\ScipLaravel\Blade\BladeLocalSymbolScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Support\SourceRange;
use PHPUnit\Framework\TestCase;

final class BladeScannerCacheTest extends TestCase
{
    public function test_directive_scanner_reuses_cached_scans_for_identical_contents(): void
    {
        $cache = new class extends BladeRuntimeCache {
            public int $resolverCalls = 0;
            private array $store = [];

            public function remember(string $bucket, string $key, callable $resolver): mixed
            {
                $compositeKey = $bucket . ':' . $key;

                if (array_key_exists($compositeKey, $this->store)) {
                    return $this->store[$compositeKey];
                }

                $this->resolverCalls++;

                return $this->store[$compositeKey] = $resolver();
            }
        };

        $scanner = new BladeDirectiveScanner($cache);
        $contents = "@lang('greeting') @error('name')";

        $scanner->scanTranslationReferences($contents);
        $firstCalls = $cache->resolverCalls;
        $scanner->scanValidationReferences($contents);

        self::assertSame($firstCalls + 1, $cache->resolverCalls);
    }

    public function test_local_symbol_scanner_reuses_cached_declarations_and_reads(): void
    {
        $cache = new class extends BladeRuntimeCache {
            public int $resolverCalls = 0;
            private array $store = [];

            public function remember(string $bucket, string $key, callable $resolver): mixed
            {
                $compositeKey = $bucket . ':' . $key;

                if (array_key_exists($compositeKey, $this->store)) {
                    return $this->store[$compositeKey];
                }

                $this->resolverCalls++;

                return $this->store[$compositeKey] = $resolver();
            }
        };

        $scanner = new BladeLocalSymbolScanner($cache);
        $contents = <<<'BLADE'
            @props(['name'])
            <div>{{ $name }}</div>
            BLADE;

        $declarations = $scanner->scanDeclarations($contents);
        self::assertNotEmpty($declarations);

        $afterDeclarations = $cache->resolverCalls;
        $scanner->scanDeclarations($contents);
        self::assertSame($afterDeclarations, $cache->resolverCalls);

        $reads = $scanner->scanVariableReads($contents, $declarations);
        self::assertNotEmpty($reads);

        $afterReads = $cache->resolverCalls;
        $scanner->scanVariableReads($contents, $declarations);
        self::assertSame($afterReads, $cache->resolverCalls);
    }

    public function test_local_symbol_scanner_scans_multiple_declaration_groups_in_one_pass(): void
    {
        $cache = new class extends BladeRuntimeCache {
            public int $resolverCalls = 0;
            private array $store = [];

            public function remember(string $bucket, string $key, callable $resolver): mixed
            {
                $compositeKey = $bucket . ':' . $key;

                if (array_key_exists($compositeKey, $this->store)) {
                    return $this->store[$compositeKey];
                }

                $this->resolverCalls++;

                return $this->store[$compositeKey] = $resolver();
            }
        };

        $scanner = new BladeLocalSymbolScanner($cache);
        $contents = <<<'BLADE'
            @props(['name'])
            <div>{{ $name }}</div>
            BLADE;

        $declarations = $scanner->scanDeclarations($contents);
        $zeroRange = SourceRange::fromOffsets($contents, 0, 0);
        $componentDeclarations = [
            new BladeLocalSymbolDeclaration(
                kind: 'component',
                name: 'name',
                symbol: 'property name',
                range: $zeroRange,
                enclosingRange: $zeroRange,
            ),
        ];

        $afterDeclarations = $cache->resolverCalls;
        $groups = $scanner->scanVariableReadsByGroups($contents, [$declarations, $componentDeclarations]);

        self::assertCount(2, $groups);
        self::assertCount(1, $groups[0]);
        self::assertCount(1, $groups[1]);
        self::assertSame('local blade-prop-name', $groups[0][0]->symbol);
        self::assertSame('property name', $groups[1][0]->symbol);

        $afterGroupedReads = $cache->resolverCalls;
        $scanner->scanVariableReadsByGroups($contents, [$declarations, $componentDeclarations]);
        self::assertSame($afterGroupedReads, $cache->resolverCalls);
        self::assertGreaterThan($afterDeclarations, $afterGroupedReads);
    }

    public function test_route_reference_scanner_captures_helper_method_forms(): void
    {
        $scanner = new BladeDirectiveScanner();
        $references = $scanner->scanRouteReferences(<<<'BLADE'
            {{ route('dashboard') }}
            {{ to_route('dashboard') }}
            @if (request()->routeIs('dashboard'))
                <span>Active</span>
            @endif
            {{ redirect()->route('settings.profile')->getTargetUrl() }}
            BLADE);

        $literals = array_map(static fn($reference): string => $reference->literal, $references);
        sort($literals);

        self::assertSame(
            [
                'dashboard',
                'dashboard',
                'dashboard',
                'settings.profile',
            ],
            $literals,
        );
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

use function is_dir;
use function is_int;
use function is_string;
use function strtolower;

final class LivewireServerSurfaceExtractor
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @return list<LivewireServerSurfaceReference>
     */
    public function references(string $projectRoot): array
    {
        $root = $projectRoot . '/app/Livewire';

        if (!is_file($projectRoot . '/composer.json') || !is_dir($root = $projectRoot . '/app/Livewire')) {
            return [];
        }

        return $this->analysisCache->remember('livewire-server-surface-references', $projectRoot, function () use (
            $root,
        ): array {
            $references = [];

            foreach ($this->analysisCache->phpFilesInRoots([$root]) as $filePath) {
                $contents = $this->analysisCache->contents($filePath);
                $ast = $this->analysisCache->resolvedAst($filePath);

                if ($contents === null || $ast === null) {
                    continue;
                }

                foreach ($this->nodeFinder->findInstanceOf($ast, MethodCall::class) as $call) {
                    $method = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;

                    if ($method === 'stream') {
                        $target = $this->namedStringArgument($call, 'to', $contents) ?? $this->positionalStringArgument(
                            $call,
                            0,
                            $contents,
                        );

                        if ($target !== null) {
                            $references[] = new LivewireServerSurfaceReference(
                                filePath: $filePath,
                                kind: 'stream',
                                name: $target['value'],
                                range: $target['range'],
                            );
                        }

                        continue;
                    }

                    if ($method === 'to') {
                        $target = $this->namedStringArgument($call, 'ref', $contents);

                        if ($target !== null && $call->var instanceof MethodCall && $this->isStreamCall($call->var)) {
                            $references[] = new LivewireServerSurfaceReference(
                                filePath: $filePath,
                                kind: 'ref',
                                name: $target['value'],
                                range: $target['range'],
                            );
                        }
                    }
                }
            }

            return $references;
        });
    }

    private function isStreamCall(MethodCall $call): bool
    {
        return $call->name instanceof Identifier && strtolower($call->name->toString()) === 'stream';
    }

    /**
     * @return ?array{value: string, range: SourceRange}
     */
    private function namedStringArgument(MethodCall $call, string $name, string $contents): ?array
    {
        foreach ($call->getArgs() as $argument) {
            if (
                !$argument instanceof Arg
                || !$argument->name instanceof Identifier
                || strtolower($argument->name->toString()) !== strtolower($name)
                || !$argument->value instanceof String_
            ) {
                continue;
            }

            $range = $this->stringRange($argument->value, $contents);

            if ($range === null || $argument->value->value === '') {
                return null;
            }

            return ['value' => $argument->value->value, 'range' => $range];
        }

        return null;
    }

    /**
     * @return ?array{value: string, range: SourceRange}
     */
    private function positionalStringArgument(MethodCall $call, int $index, string $contents): ?array
    {
        $argument = $call->getArgs()[$index] ?? null;

        if (!$argument instanceof Arg || !$argument->value instanceof String_) {
            return null;
        }

        $range = $this->stringRange($argument->value, $contents);

        if ($range === null || $argument->value->value === '') {
            return null;
        }

        return ['value' => $argument->value->value, 'range' => $range];
    }

    private function stringRange(String_ $string, string $contents): ?SourceRange
    {
        $start = $string->getStartFilePos();
        $end = $string->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start + 1, $end);
    }
}

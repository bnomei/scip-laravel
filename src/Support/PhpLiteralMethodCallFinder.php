<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

use function array_fill_keys;
use function array_map;
use function in_array;
use function is_string;
use function ltrim;
use function strtolower;

final class PhpLiteralMethodCallFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @param array<string, array{methods: list<string>, helper_literal?: ?string}> $helperMethods
     * @return list<PhpLiteralMethodCall>
     */
    public function find(string $projectRoot, array $helperMethods): array
    {
        if ($helperMethods === []) {
            return [];
        }

        $normalized = [];

        foreach ($helperMethods as $helper => $config) {
            $normalizedHelper = strtolower(ltrim($helper, '\\'));
            $normalized[$normalizedHelper] = [
                'methods' => array_fill_keys(array_map(static fn(string $method): string => strtolower(
                    $method,
                ), $config['methods']), true),
                'helper_literal' => isset($config['helper_literal']) && is_string($config['helper_literal'])
                    ? $config['helper_literal']
                    : null,
            ];
        }

        $matches = [];

        foreach ($this->projectCalls($projectRoot) as $call) {
            $config = $normalized[$call->helper] ?? null;

            if ($config === null || !isset($config['methods'][$call->method])) {
                continue;
            }

            $expectedHelperLiteral = $config['helper_literal'];

            if ($expectedHelperLiteral !== null) {
                if ($call->helperLiteral !== $expectedHelperLiteral) {
                    continue;
                }
            } elseif ($call->helperLiteral !== null) {
                continue;
            }

            $matches[] = $call;
        }

        return $matches;
    }

    /**
     * @return list<PhpLiteralMethodCall>
     */
    private function projectCalls(string $projectRoot): array
    {
        return $this->analysisCache->remember('php-literal-method-calls', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $calls = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                foreach ($this->findInFile($filePath) as $call) {
                    $calls[] = $call;
                }
            }

            return $calls;
        });
    }

    /**
     * @return list<PhpLiteralMethodCall>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }

        $calls = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, MethodCall::class) as $call) {
            $matched = $this->matchMethodCall($call, $filePath, $contents);

            if ($matched !== null) {
                $calls[] = $matched;
            }
        }

        return $calls;
    }

    private function matchMethodCall(MethodCall $call, string $filePath, string $contents): ?PhpLiteralMethodCall
    {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        if (!$call->var instanceof FuncCall || !$call->var->name instanceof Name) {
            return null;
        }

        $helper = strtolower(ltrim($call->var->name->toString(), '\\'));
        $helperArgs = $call->var->getArgs();
        $helperLiteral = null;

        if ($helperArgs !== []) {
            $helperArg = $helperArgs[0] ?? null;

            if ($helperArg === null || !$helperArg->value instanceof String_) {
                return null;
            }

            $helperLiteral = $helperArg->value->value;

            if (!in_array($helper, ['app'], true)) {
                return null;
            }
        }

        $method = strtolower($call->name->toString());
        $argument = $call->getArgs()[0] ?? null;

        if ($argument === null || !$argument->value instanceof String_) {
            return null;
        }

        $start = $argument->value->getStartFilePos();
        $end = $argument->value->getEndFilePos();

        if ($start < 0 || $end < 0) {
            return null;
        }

        return new PhpLiteralMethodCall(
            filePath: $filePath,
            helper: $helper,
            helperLiteral: $helperLiteral,
            method: $method,
            literal: $argument->value->value,
            range: SourceRange::fromOffsets($contents, $start + 1, $end),
        );
    }
}

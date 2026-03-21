<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

use function array_fill_keys;
use function array_map;
use function in_array;
use function ltrim;
use function strtolower;

final class PhpLiteralCallFinder
{
    /**
     * @var array<string, list<PhpLiteralCall>>
     */
    private static array $projectCache = [];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public static function reset(): void
    {
        self::$projectCache = [];
    }

    /**
     * @param list<string> $functions
     * @param array<string, list<string>> $staticMethods
     * @return list<PhpLiteralCall>
     */
    public function find(string $projectRoot, array $functions = [], array $staticMethods = []): array
    {
        if ($functions === [] && $staticMethods === []) {
            return [];
        }

        $functions = array_fill_keys(array_map(static fn(string $function): string => strtolower(ltrim(
            $function,
            '\\',
        )), $functions), true);

        $normalizedStaticMethods = [];

        foreach ($staticMethods as $className => $methods) {
            $normalizedStaticMethods[strtolower(ltrim($className, '\\'))] = array_map(
                static fn(string $method): string => strtolower($method),
                $methods,
            );
        }

        $calls = [];

        foreach ($this->projectCalls($projectRoot) as $call) {
            if (isset($functions[$call->callee])) {
                $calls[] = $call;
                continue;
            }

            [$className, $methodName] = explode('::', $call->callee, 2) + [null, null];

            if (
                is_string($className)
                && is_string($methodName)
                && isset($normalizedStaticMethods[$className])
                && in_array($methodName, $normalizedStaticMethods[$className], true)
            ) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @return list<PhpLiteralCall>
     */
    private function projectCalls(string $projectRoot): array
    {
        return $this->analysisCache->remember('php-literal-calls', $projectRoot, function () use ($projectRoot): array {
            $calls = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                foreach ($this->findInFile($filePath) as $call) {
                    $calls[] = $call;
                }
            }

            return self::$projectCache[$projectRoot] = $calls;
        });
    }

    /**
     * @return list<PhpLiteralCall>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }

        $calls = [];

        foreach ($this->nodeFinder->find(
            $ast,
            static fn(Node $node): bool => $node instanceof FuncCall || $node instanceof StaticCall,
        ) as $node) {
            $call = $node instanceof FuncCall
                ? $this->matchFunctionCall($node, $filePath, $contents)
                : $this->matchStaticCall($node, $filePath, $contents);

            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     */
    private function matchFunctionCall(FuncCall $call, string $filePath, string $contents): ?PhpLiteralCall
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));

        return $this->literalCallFromArguments($filePath, $contents, $name, $call->getArgs());
    }

    /**
     */
    private function matchStaticCall(StaticCall $call, string $filePath, string $contents): ?PhpLiteralCall
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }

        $resolvedClass = $call->class->getAttribute('resolvedName');
        $className = $resolvedClass instanceof Name ? $resolvedClass->toString() : $call->class->toString();
        $className = strtolower(ltrim($className, '\\'));
        $methodName = strtolower($call->name->toString());

        return $this->literalCallFromArguments($filePath, $contents, $className . '::' . $methodName, $call->getArgs());
    }

    /**
     * @param list<\PhpParser\Node\Arg> $arguments
     */
    private function literalCallFromArguments(
        string $filePath,
        string $contents,
        string $callee,
        array $arguments,
    ): ?PhpLiteralCall {
        $argument = $arguments[0] ?? null;

        if ($argument === null || !$argument->value instanceof String_) {
            return null;
        }

        $start = $argument->value->getStartFilePos();
        $end = $argument->value->getEndFilePos();

        if ($start < 0 || $end < 0) {
            return null;
        }

        return new PhpLiteralCall(
            filePath: $filePath,
            callee: $callee,
            literal: $argument->value->value,
            range: SourceRange::fromOffsets($contents, $start + 1, $end),
        );
    }
}

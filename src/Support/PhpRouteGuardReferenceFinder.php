<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;

use function array_merge;
use function ltrim;
use function strtolower;

final class PhpRouteGuardReferenceFinder
{
    /**
     * @var array<string, list<RouteGuardReference>>
     */
    private static array $projectCache = [];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    /**
     * @var array<string, true>
     */
    private const ROUTE_FACADES = [
        'illuminate\\support\\facades\\route' => true,
        'route' => true,
    ];

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
     * @return list<RouteGuardReference>
     */
    public function find(string $projectRoot): array
    {
        if (isset(self::$projectCache[$projectRoot])) {
            return self::$projectCache[$projectRoot];
        }

        $references = [];

        foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
            $references = array_merge($references, $this->findInFile($filePath));
        }

        return self::$projectCache[$projectRoot] = $references;
    }

    /**
     * @return list<RouteGuardReference>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }
        $references = [];

        foreach ($this->nodeFinder->find(
            $ast,
            static fn(Node $node): bool => $node instanceof Expression,
        ) as $statement) {
            $references = array_merge($references, $this->referencesFromExpression(
                $statement->expr,
                $filePath,
                $contents,
            ));
        }

        return $references;
    }

    /**
     * @return list<RouteGuardReference>
     */
    private function referencesFromExpression(Expr $expr, string $filePath, string $contents): array
    {
        $chain = $this->callChain($expr);

        if ($chain === [] || !$this->containsRouteRoot($chain)) {
            return [];
        }

        $routeName = $this->routeName($chain);

        if ($routeName === null) {
            return [];
        }

        $references = [];

        foreach ($chain as $call) {
            if (!$call instanceof MethodCall) {
                continue;
            }

            $method = $this->callName($call);

            if ($method === 'can') {
                $ability = $call->getArgs()[0]->value ?? null;

                if ($ability instanceof String_) {
                    $range = $this->stringRange($ability, $contents);

                    if ($range !== null) {
                        $references[] = new RouteGuardReference(
                            $filePath,
                            $routeName,
                            'ability',
                            $ability->value,
                            $range,
                        );
                    }
                }

                continue;
            }

            if ($method === 'middleware' || $method === 'withoutmiddleware') {
                foreach ($call->getArgs() as $argument) {
                    if (!$argument instanceof Arg) {
                        continue;
                    }

                    foreach ($this->middlewareReferences(
                        $argument->value,
                        $filePath,
                        $routeName,
                        $contents,
                        $method === 'withoutmiddleware' ? 'excluded' : 'applied',
                    ) as $reference) {
                        $references[] = $reference;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @return list<RouteGuardReference>
     */
    private function middlewareReferences(
        Expr $expr,
        string $filePath,
        string $routeName,
        string $contents,
        string $mode,
    ): array {
        if ($expr instanceof Array_) {
            $references = [];

            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }

                foreach ($this->middlewareReferences(
                    $item->value,
                    $filePath,
                    $routeName,
                    $contents,
                    $mode,
                ) as $reference) {
                    $references[] = $reference;
                }
            }

            return $references;
        }

        if ($expr instanceof String_) {
            $range = $this->stringRange($expr, $contents);

            if ($range === null || $expr->value === '') {
                return [];
            }

            if (str_starts_with($expr->value, 'can:')) {
                $ability = explode(',', substr($expr->value, 4), 2)[0] ?? '';

                return (
                    $ability !== ''
                        ? [new RouteGuardReference($filePath, $routeName, 'ability', $ability, $range, $mode)]
                        : []
                );
            }

            return [new RouteGuardReference($filePath, $routeName, 'middleware-alias', $expr->value, $range, $mode)];
        }

        if ($expr instanceof ClassConstFetch && $this->isClassConstFetch($expr)) {
            $className = $this->resolvedName($expr->class);
            $range = $this->expressionRange($expr, $contents);

            if ($className === null || $range === null) {
                return [];
            }

            return [new RouteGuardReference($filePath, $routeName, 'middleware-class', $className, $range, $mode)];
        }

        return [];
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     */
    private function containsRouteRoot(array $chain): bool
    {
        foreach ($chain as $call) {
            if (!$call instanceof StaticCall || !$call->class instanceof Name) {
                continue;
            }

            $resolved = $call->class->getAttribute('resolvedName');
            $className = $resolved instanceof Name ? $resolved->toString() : $call->class->toString();
            $className = strtolower(ltrim($className, '\\'));

            if (isset(self::ROUTE_FACADES[$className])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     */
    private function routeName(array $chain): ?string
    {
        foreach ($chain as $call) {
            if (!$call instanceof MethodCall || $this->callName($call) !== 'name') {
                continue;
            }

            $argument = $call->getArgs()[0]->value ?? null;

            if ($argument instanceof String_ && $argument->value !== '') {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * @return list<MethodCall|StaticCall>
     */
    private function callChain(Expr $expr): array
    {
        $calls = [];
        $current = $expr;

        while ($current instanceof MethodCall || $current instanceof StaticCall) {
            $calls[] = $current;
            $current = $current instanceof MethodCall ? $current->var : null;
        }

        return $calls;
    }

    private function callName(MethodCall|StaticCall $call): ?string
    {
        return $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
    }

    private function isClassConstFetch(ClassConstFetch $expr): bool
    {
        return (
            $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
            && $expr->class instanceof Name
        );
    }

    private function resolvedName(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        $className = $resolved instanceof Name ? $resolved->toString() : $name->toString();

        $className = ltrim($className, '\\');

        return $className !== '' ? $className : null;
    }

    private function expressionRange(Expr $expr, string $contents): ?SourceRange
    {
        $start = $expr->getStartFilePos();
        $end = $expr->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < 0) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
    }

    private function stringRange(String_ $expr, string $contents): ?SourceRange
    {
        $start = $expr->getStartFilePos();
        $end = $expr->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < 0) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start + 1, $end);
    }
}

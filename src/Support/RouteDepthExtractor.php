<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;

use function array_merge;
use function array_reverse;
use function ltrim;
use function strtolower;

final class RouteDepthExtractor
{
    /**
     * @var array<string, array{bindings: list<RouteExplicitBindingFact>, metadata: list<RouteDepthReference>}>
     */
    private static array $scanCache = [];

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
        self::$scanCache = [];
    }

    /**
     * @return list<RouteExplicitBindingFact>
     */
    public function explicitBindings(string $projectRoot): array
    {
        return $this->scan($projectRoot)['bindings'];
    }

    /**
     * @return list<RouteDepthReference>
     */
    public function routeMetadata(string $projectRoot): array
    {
        return $this->scan($projectRoot)['metadata'];
    }

    private function explicitBindingFact(Expr $expr, string $filePath, string $contents): ?RouteExplicitBindingFact
    {
        if (
            !$expr instanceof StaticCall
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || !isset(
                self::ROUTE_FACADES[strtolower(ltrim(
                    ($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(),
                    '\\',
                ))],
            )
        ) {
            return null;
        }

        $method = strtolower($expr->name->toString());
        $parameter = $expr->getArgs()[0]->value ?? null;

        if (!$parameter instanceof String_ || $parameter->value === '') {
            return null;
        }

        $classLiteral = match ($method) {
            'model' => $this->classLiteral($expr->getArgs()[1] ?? null, $contents),
            'bind' => $this->bindingCallbackClassLiteral($expr->getArgs()[1] ?? null, $contents),
            default => null,
        };

        if ($classLiteral === null) {
            return null;
        }

        return new RouteExplicitBindingFact(
            filePath: $filePath,
            parameter: $parameter->value,
            className: $classLiteral['class'],
            range: $classLiteral['range'],
        );
    }

    private function routeMetadataReference(Expr $expr, string $filePath, string $contents): ?RouteDepthReference
    {
        $chain = $this->callChain($expr);

        if ($chain === [] || !$this->containsRouteRoot($chain)) {
            return null;
        }

        $routeName = $this->routeName($chain);

        if ($routeName === null) {
            return null;
        }

        $scopeBindingsState = null;
        $missingTargetKind = null;
        $missingTarget = null;
        $missingTargetRange = null;
        $authorizationAbility = null;
        $authorizationTargetLiteral = null;
        $authorizationTargetRange = null;
        $authorizationTargetClassName = null;

        foreach ($chain as $call) {
            if (!$call instanceof MethodCall || !$call->name instanceof Identifier) {
                continue;
            }

            $method = strtolower($call->name->toString());

            if ($method === 'scopebindings') {
                $scopeBindingsState = 'enabled';
                continue;
            }

            if ($method === 'withoutscopedbindings') {
                $scopeBindingsState = 'disabled';
                continue;
            }

            if ($method === 'missing') {
                $missing = $this->missingTarget($call->getArgs()[0] ?? null, $contents);

                if ($missing !== null) {
                    $missingTargetKind = $missing['kind'];
                    $missingTarget = $missing['target'];
                    $missingTargetRange = $missing['range'];
                }

                continue;
            }

            if ($method === 'can') {
                $ability = $call->getArgs()[0]->value ?? null;
                $target = $call->getArgs()[1] ?? null;

                if ($ability instanceof String_ && $ability->value !== '') {
                    $authorizationAbility = $ability->value;
                }

                if ($target?->value instanceof String_) {
                    $range = $this->stringRange($target->value, $contents);

                    if ($range !== null && $target->value->value !== '') {
                        $authorizationTargetLiteral = $target->value->value;
                        $authorizationTargetRange = $range;
                    }
                }

                $targetClass = $this->classLiteral($target, $contents);

                if ($targetClass !== null) {
                    $authorizationTargetClassName = $targetClass['class'];
                    $authorizationTargetRange = $targetClass['range'];
                }
            }
        }

        if (
            $scopeBindingsState === null
            && $missingTarget === null
            && $authorizationAbility === null
            && $authorizationTargetClassName === null
            && $authorizationTargetLiteral === null
        ) {
            return null;
        }

        return new RouteDepthReference(
            filePath: $filePath,
            routeName: $routeName,
            scopeBindingsState: $scopeBindingsState,
            missingTargetKind: $missingTargetKind,
            missingTarget: $missingTarget,
            missingTargetRange: $missingTargetRange,
            authorizationAbility: $authorizationAbility,
            authorizationTargetLiteral: $authorizationTargetLiteral,
            authorizationTargetRange: $authorizationTargetRange,
            authorizationTargetClassName: $authorizationTargetClassName,
        );
    }

    /**
     * @return ?array{kind: string, target: string, range: SourceRange}
     */
    private function missingTarget(?Arg $argument, string $contents): ?array
    {
        $value = $argument?->value;

        if ($value instanceof ArrowFunction) {
            return $this->missingTargetExpression($value->expr, $contents);
        }

        if ($value instanceof Closure && $value->stmts !== []) {
            foreach ($value->stmts as $statement) {
                if (!$statement instanceof Node\Stmt\Return_) {
                    continue;
                }

                return $this->missingTargetExpression($statement->expr, $contents);
            }
        }

        return null;
    }

    /**
     * @return ?array{kind: string, target: string, range: SourceRange}
     */
    private function missingTargetExpression(?Expr $expr, string $contents): ?array
    {
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $function = strtolower(ltrim(($expr->name->getAttribute('resolvedName') ?? $expr->name)->toString(), '\\'));

            if ($function === 'to_route') {
                $target = $expr->getArgs()[0]->value ?? null;

                if ($target instanceof String_ && $target->value !== '') {
                    $range = $this->stringRange($target, $contents);

                    return (
                        $range === null ? null : ['kind' => 'route-name', 'target' => $target->value, 'range' => $range]
                    );
                }
            }

            if ($function === 'redirect') {
                $target = $expr->getArgs()[0]->value ?? null;

                if ($target instanceof String_ && str_starts_with($target->value, '/')) {
                    $range = $this->stringRange($target, $contents);

                    return $range === null ? null : ['kind' => 'path', 'target' => $target->value, 'range' => $range];
                }
            }
        }

        return null;
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function bindingCallbackClassLiteral(?Arg $argument, string $contents): ?array
    {
        $value = $argument?->value;
        $expr = null;

        if ($value instanceof ArrowFunction) {
            $expr = $value->expr;
        } elseif ($value instanceof Closure && $value->stmts !== []) {
            foreach ($value->stmts as $statement) {
                if ($statement instanceof Node\Stmt\Return_) {
                    $expr = $statement->expr;
                    break;
                }
            }
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name) {
            $range = $this->nameRange($expr->class, $contents);

            return (
                $range === null
                    ? null
                    : [
                        'class' => ltrim(
                            ($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(),
                            '\\',
                        ),
                        'range' => $range,
                    ]
            );
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $range = $this->nameRange($expr->class, $contents);

            return (
                $range === null
                    ? null
                    : [
                        'class' => ltrim(
                            ($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(),
                            '\\',
                        ),
                        'range' => $range,
                    ]
            );
        }

        if (
            $expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            $range = $this->nameRange($expr->class, $contents);

            return (
                $range === null
                    ? null
                    : [
                        'class' => ltrim(
                            ($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(),
                            '\\',
                        ),
                        'range' => $range,
                    ]
            );
        }

        return null;
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function classLiteral(?Arg $argument, string $contents): ?array
    {
        $expr = $argument?->value;

        if (
            !$expr instanceof ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'class'
        ) {
            return null;
        }

        $range = $this->nameRange($expr->class, $contents);

        return (
            $range === null
                ? null
                : [
                    'class' => ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\'),
                    'range' => $range,
                ]
        );
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

    private function nameRange(Name $name, string $contents): ?SourceRange
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
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

            $className = strtolower(ltrim(
                ($call->class->getAttribute('resolvedName') ?? $call->class)->toString(),
                '\\',
            ));

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
            if (
                !$call instanceof MethodCall
                || !$call->name instanceof Identifier
                || strtolower($call->name->toString()) !== 'name'
            ) {
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

        return array_reverse($calls);
    }

    /**
     * @return array{bindings: list<RouteExplicitBindingFact>, metadata: list<RouteDepthReference>}
     */
    private function scan(string $projectRoot): array
    {
        return $this->analysisCache->remember('route-depth-scan', $projectRoot, function () use ($projectRoot): array {
            $bindings = [];
            $metadata = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                $payload = $this->parsedStatements($filePath);

                if ($payload === null) {
                    continue;
                }

                [$contents, $statements] = $payload;

                foreach ($statements as $statement) {
                    $binding = $this->explicitBindingFact($statement->expr, $filePath, $contents);

                    if ($binding !== null) {
                        $bindings[] = $binding;
                    }

                    $reference = $this->routeMetadataReference($statement->expr, $filePath, $contents);

                    if ($reference !== null) {
                        $metadata[] = $reference;
                    }
                }
            }

            return self::$scanCache[$projectRoot] = [
                'bindings' => $bindings,
                'metadata' => $metadata,
            ];
        });
    }

    /**
     * @return ?array{0: string, 1: list<Expression>}
     */
    private function parsedStatements(string $filePath): ?array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return null;
        }

        return [$contents, $this->nodeFinder->findInstanceOf($ast, Expression::class)];
    }
}

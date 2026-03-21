<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use Scip\SyntaxKind;

use function array_is_list;
use function array_reverse;
use function count;
use function in_array;
use function ksort;
use function ltrim;
use function sort;
use function strrpos;
use function strtolower;
use function trim;

final class PhpRouteDeclarationFinder
{
    /**
     * @var array<string, list<PhpRouteDeclaration>>
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

    /**
     * @var array<string, array<string, true>>
     */
    private const SUPPORTED_STATIC_ROUTE_ROOTS = [
        'livewire\\volt\\volt' => [
            'route' => true,
        ],
    ];

    /**
     * @var array<string, array{uri: int, action: int}>
     */
    private const SIMPLE_CREATORS = [
        'get' => ['uri' => 0, 'action' => 1],
        'post' => ['uri' => 0, 'action' => 1],
        'put' => ['uri' => 0, 'action' => 1],
        'patch' => ['uri' => 0, 'action' => 1],
        'delete' => ['uri' => 0, 'action' => 1],
        'options' => ['uri' => 0, 'action' => 1],
        'any' => ['uri' => 0, 'action' => 1],
        'match' => ['uri' => 1, 'action' => 2],
        'view' => ['uri' => 0, 'action' => 1],
        'redirect' => ['uri' => 0, 'action' => 1],
        'permanentredirect' => ['uri' => 0, 'action' => 1],
        'livewire' => ['uri' => 0, 'action' => 1],
        'route' => ['uri' => 0, 'action' => 1],
    ];

    /**
     * @var array<string, array{name: int, controller: int, type: string}>
     */
    private const RESOURCE_CREATORS = [
        'resource' => ['name' => 0, 'controller' => 1, 'type' => 'resource'],
        'apiresource' => ['name' => 0, 'controller' => 1, 'type' => 'api-resource'],
        'singleton' => ['name' => 0, 'controller' => 1, 'type' => 'singleton'],
        'apisingleton' => ['name' => 0, 'controller' => 1, 'type' => 'api-singleton'],
    ];

    /**
     * @var array<string, true>
     */
    private const WRAPPER_METHODS = [
        'controller' => true,
        'domain' => true,
        'name' => true,
        'prefix' => true,
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
     * @return list<PhpRouteDeclaration>
     */
    public function find(string $projectRoot): array
    {
        return $this->analysisCache->remember('php-route-declarations', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $declarations = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                foreach ($this->findInFile($filePath) as $declaration) {
                    $declarations[] = $declaration;
                }
            }

            return self::$projectCache[$projectRoot] = $declarations;
        });
    }

    /**
     * @return list<PhpRouteDeclaration>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }
        $declarations = [];

        foreach ($this->nodeFinder->find(
            $ast,
            static fn(Node $node): bool => $node instanceof Expression,
        ) as $statement) {
            $declaration = $this->matchExpression($statement->expr, $filePath, $contents);

            if ($declaration !== null) {
                $declarations[] = $declaration;
            }
        }

        return $declarations;
    }

    private function matchExpression(Expr $expr, string $filePath, string $contents): ?PhpRouteDeclaration
    {
        $chain = $this->callChain($expr);

        if ($chain === [] || !$this->containsSupportedRouteRoot($chain)) {
            return null;
        }

        $creator = $this->creatorCall($chain);

        if ($creator === null) {
            return null;
        }

        $context = $this->mergeContexts($this->groupContext($expr), $this->chainWrapperContext($chain, $creator));

        $resourceDeclaration = $this->resourceDeclaration($chain, $creator, $filePath, $contents, $context);

        if ($resourceDeclaration !== null) {
            return $resourceDeclaration;
        }

        $name = $this->effectiveNameLiteral($chain, $contents, $context);
        $uri = $this->prefixedUri($context['uriPrefix'], $this->uriLiteral($creator));
        $controller = $this->controllerTarget($creator, $contents, $context['controllerClass']);
        $componentName = $creator === null ? null : $this->componentName($creator);
        $viewName = $this->viewName($creator);
        $redirectTarget = $this->redirectTarget($creator);
        $parameterDefaults = $this->parameterDefaults($chain, $creator);
        $anchorRange = $name['range'] ?? $this->creatorAnchorRange($creator, $contents);
        $targetRange = $this->actionRange($creator, $contents);

        if (
            $name === null
            && $controller === null
            && $componentName === null
            && $viewName === null
            && $redirectTarget === null
            && $uri === null
        ) {
            return null;
        }

        return new PhpRouteDeclaration(
            filePath: $filePath,
            uriLiteral: $uri,
            nameLiteral: $name['literal'] ?? null,
            nameRange: $name['range'] ?? null,
            anchorRange: $anchorRange,
            targetRange: $targetRange,
            controllerClass: $controller['class'] ?? null,
            controllerMethod: $controller['method'] ?? null,
            controllerRange: $controller['range'] ?? null,
            controllerSyntaxKind: $controller['syntax_kind'] ?? 0,
            viewName: $viewName,
            redirectTarget: $redirectTarget,
            componentName: $componentName,
            parameterDefaults: $parameterDefaults,
        );
    }

    /**
     * @param array{namePrefix: string, uriPrefix: string, controllerClass: ?string} $context
     */
    private function resourceDeclaration(
        array $chain,
        MethodCall|StaticCall $creator,
        string $filePath,
        string $contents,
        array $context,
    ): ?PhpRouteDeclaration {
        $method = $this->callName($creator);

        if ($method === null || !isset(self::RESOURCE_CREATORS[$method])) {
            return null;
        }

        $definition = self::RESOURCE_CREATORS[$method];
        $nameArgument = $creator->getArgs()[$definition['name']] ?? null;
        $controllerArgument = $creator->getArgs()[$definition['controller']] ?? null;

        if (
            !$nameArgument instanceof Arg
            || !$nameArgument->value instanceof String_
            || !$controllerArgument instanceof Arg
        ) {
            return null;
        }

        $resourceName = trim($nameArgument->value->value);
        $anchorRange = $this->stringRange($nameArgument->value, $contents);
        $controllerTarget = $this->resourceControllerTarget($controllerArgument->value, $contents);

        if ($resourceName === '' || $anchorRange === null || $controllerTarget === null) {
            return null;
        }

        return new PhpRouteDeclaration(
            filePath: $filePath,
            uriLiteral: null,
            nameLiteral: null,
            nameRange: null,
            anchorRange: $anchorRange,
            targetRange: $controllerTarget['range'],
            controllerClass: $controllerTarget['class'],
            controllerMethod: null,
            controllerRange: $controllerTarget['range'],
            controllerSyntaxKind: $controllerTarget['syntax_kind'],
            resourceName: $context['namePrefix'] . $resourceName,
            resourceType: $definition['type'],
            generatedRouteNames: $this->resourceGeneratedRouteNames(
                chain: $chain,
                creator: $creator,
                resourceName: $resourceName,
                resourceType: $definition['type'],
                context: $context,
            ) ?? [],
            parameterDefaults: [],
        );
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @param array{namePrefix: string, uriPrefix: string, controllerClass: ?string} $context
     * @return list<string>|null
     */
    private function resourceGeneratedRouteNames(
        array $chain,
        MethodCall|StaticCall $creator,
        string $resourceName,
        string $resourceType,
        array $context,
    ): ?array {
        $actions = $this->resourceActions($resourceType);
        $namesPrefix = null;
        $namesByAction = [];
        $only = null;
        $except = [];
        $creatable = false;
        $destroyable = false;

        foreach ($chain as $call) {
            if ($call === $creator) {
                break;
            }

            if (!$call instanceof MethodCall) {
                continue;
            }

            $method = $this->callName($call);

            if ($method === null) {
                continue;
            }

            if ($method === 'only') {
                $only = $this->literalStringList($call->getArgs()[0] ?? null);

                if ($only === null) {
                    return null;
                }

                continue;
            }

            if ($method === 'except') {
                $except = $this->literalStringList($call->getArgs()[0] ?? null);

                if ($except === null) {
                    return null;
                }

                continue;
            }

            if ($method === 'names') {
                $nameArgument = $call->getArgs()[0] ?? null;

                if (!$nameArgument instanceof Arg) {
                    return null;
                }

                if ($nameArgument->value instanceof String_) {
                    $namesPrefix = trim($nameArgument->value->value);
                    continue;
                }

                $namesByAction = $this->literalStringMap($nameArgument->value);

                if ($namesByAction === null) {
                    return null;
                }

                continue;
            }

            if ($method === 'creatable') {
                $creatable = true;
                continue;
            }

            if ($method === 'destroyable') {
                $destroyable = true;
            }
        }

        if ($resourceType === 'singleton') {
            if ($creatable) {
                $actions = ['show', 'edit', 'update', 'create', 'store', 'destroy'];
            } elseif ($destroyable && !in_array('destroy', $actions, true)) {
                $actions[] = 'destroy';
            }
        }

        if ($only !== null) {
            $actions = array_values(array_intersect($actions, $only));
        }

        if ($except !== []) {
            $actions = array_values(array_diff($actions, $except));
        }

        $baseName = $namesPrefix ?? $this->resourceRouteBaseName($resourceName);

        if ($baseName === null) {
            return null;
        }

        $routeNames = [];

        foreach ($actions as $action) {
            if (isset($namesByAction[$action])) {
                $routeName = trim($namesByAction[$action]);

                if ($routeName !== '') {
                    $routeNames[$routeName] = true;
                }

                continue;
            }

            $routeName = trim($context['namePrefix'] . $baseName . '.' . $action, '.');

            if ($routeName !== '') {
                $routeNames[$routeName] = true;
            }
        }

        $routeNames = array_keys($routeNames);
        sort($routeNames);

        return $routeNames;
    }

    /**
     * @return list<string>
     */
    private function resourceActions(string $resourceType): array
    {
        return match ($resourceType) {
            'api-resource' => ['index', 'show', 'store', 'update', 'destroy'],
            'singleton' => ['show', 'edit', 'update'],
            'api-singleton' => ['store', 'show', 'update', 'destroy'],
            default => ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'],
        };
    }

    private function resourceRouteBaseName(string $resourceName): ?string
    {
        $resourceName = trim($resourceName);

        if ($resourceName === '') {
            return null;
        }

        $slashOffset = strrpos($resourceName, '/');

        if ($slashOffset !== false) {
            $resourceName = substr($resourceName, $slashOffset + 1);
        }

        $resourceName = trim($resourceName);

        return $resourceName !== '' ? $resourceName : null;
    }

    /**
     * @return list<string>|null
     */
    private function literalStringList(mixed $argument): ?array
    {
        if (!$argument instanceof Arg) {
            return null;
        }

        if ($argument->value instanceof String_) {
            return [$argument->value->value];
        }

        if (!$argument->value instanceof Array_) {
            return null;
        }

        $values = [];

        foreach ($argument->value->items as $item) {
            if (!$item instanceof ArrayItem || !$item->value instanceof String_) {
                return null;
            }

            $values[] = $item->value->value;
        }

        return $values;
    }

    /**
     * @return array<string, string>|null
     */
    private function literalStringMap(Expr $expr): ?array
    {
        if (!$expr instanceof Array_) {
            return null;
        }

        $values = [];

        foreach ($expr->items as $item) {
            if (
                !$item instanceof ArrayItem
                || !$item->key instanceof String_
                || !$item->value instanceof String_
                || $item->key->value === ''
                || $item->value->value === ''
            ) {
                return null;
            }

            $values[$item->key->value] = $item->value->value;
        }

        return $values;
    }

    /**
     * @return array{namePrefix: string, uriPrefix: string, controllerClass: ?string}
     */
    private function groupContext(Expr $expr): array
    {
        $contexts = [];
        $current = $expr->getAttribute('parent');

        while ($current instanceof Node) {
            if (
                ($current instanceof MethodCall || $current instanceof StaticCall)
                && $this->callName($current) === 'group'
            ) {
                $chain = $this->callChain($current);

                if ($chain !== [] && $this->containsSupportedRouteRoot($chain)) {
                    $contexts[] = $this->wrapperContextFromChain($chain, $current);
                }
            }

            $current = $current->getAttribute('parent');
        }

        $context = $this->emptyContext();

        foreach (array_reverse($contexts) as $groupContext) {
            $context = $this->mergeContexts($context, $groupContext);
        }

        return $context;
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return array{namePrefix: string, uriPrefix: string, controllerClass: ?string}
     */
    private function chainWrapperContext(array $chain, MethodCall|StaticCall $creator): array
    {
        return $this->wrapperContextFromChain($chain, $creator);
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return array{namePrefix: string, uriPrefix: string, controllerClass: ?string}
     */
    private function wrapperContextFromChain(array $chain, MethodCall|StaticCall $terminal): array
    {
        $context = $this->emptyContext();

        foreach (array_reverse($chain) as $call) {
            if ($call === $terminal) {
                break;
            }

            $method = $this->callName($call);

            if (
                $method === null
                || !isset(self::WRAPPER_METHODS[$method])
                || !$call instanceof MethodCall && !$call instanceof StaticCall
            ) {
                continue;
            }

            $argument = $call->getArgs()[0] ?? null;

            if (!$argument instanceof Arg) {
                continue;
            }

            if ($method === 'name' && $argument->value instanceof String_) {
                $context['namePrefix'] .= $argument->value->value;
                continue;
            }

            if ($method === 'prefix' && $argument->value instanceof String_) {
                $context['uriPrefix'] =
                    $this->prefixedUri($context['uriPrefix'], $argument->value->value) ?? $context['uriPrefix'];
                continue;
            }

            if ($method === 'controller') {
                $controllerClass = $this->classNameLiteral($argument->value);

                if ($controllerClass !== null) {
                    $context['controllerClass'] = $controllerClass;
                }
            }
        }

        return $context;
    }

    /**
     * @return array{namePrefix: string, uriPrefix: string, controllerClass: ?string}
     */
    private function mergeContexts(array $outer, array $inner): array
    {
        return [
            'namePrefix' => $outer['namePrefix'] . $inner['namePrefix'],
            'uriPrefix' =>
                $this->prefixedUri($outer['uriPrefix'], $inner['uriPrefix'])
                    ?? $outer['uriPrefix'] . $inner['uriPrefix'],
            'controllerClass' => $inner['controllerClass'] ?? $outer['controllerClass'],
        ];
    }

    /**
     * @return array{namePrefix: string, uriPrefix: string, controllerClass: ?string}
     */
    private function emptyContext(): array
    {
        return [
            'namePrefix' => '',
            'uriPrefix' => '',
            'controllerClass' => null,
        ];
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

    /**
     * @param list<MethodCall|StaticCall> $chain
     */
    private function containsSupportedRouteRoot(array $chain): bool
    {
        foreach ($chain as $call) {
            if (!$call instanceof StaticCall || !$call->class instanceof Name) {
                continue;
            }

            $className = $this->normalizedStaticClassName($call->class);

            if (isset(self::ROUTE_FACADES[$className])) {
                return true;
            }

            $methodName = $this->callName($call);

            if ($methodName === null) {
                continue;
            }

            if (isset(self::SUPPORTED_STATIC_ROUTE_ROOTS[$className][$methodName])) {
                return true;
            }
        }

        return false;
    }

    private function normalizedStaticClassName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        $className = $resolved instanceof Name ? $resolved->toString() : $name->toString();

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return MethodCall|StaticCall|null
     */
    private function creatorCall(array $chain): MethodCall|StaticCall|null
    {
        foreach ($chain as $call) {
            $name = $this->callName($call);

            if ($name !== null && (isset(self::SIMPLE_CREATORS[$name]) || isset(self::RESOURCE_CREATORS[$name]))) {
                return $call;
            }
        }

        return null;
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @param array{namePrefix: string, uriPrefix: string, controllerClass: ?string} $context
     * @return array{literal: string, range: SourceRange}|null
     */
    private function effectiveNameLiteral(array $chain, string $contents, array $context): ?array
    {
        $name = $this->nameLiteral($chain, $contents);

        if ($name === null) {
            return null;
        }

        return [
            'literal' => $context['namePrefix'] . $name['literal'],
            'range' => $name['range'],
        ];
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return array{literal: string, range: SourceRange}|null
     */
    private function nameLiteral(array $chain, string $contents): ?array
    {
        foreach ($chain as $call) {
            if (!$call instanceof MethodCall || $this->callName($call) !== 'name') {
                continue;
            }

            $argument = $call->getArgs()[0] ?? null;

            if ($argument === null || !$argument->value instanceof String_) {
                continue;
            }

            $range = $this->stringRange($argument->value, $contents);

            if ($range === null) {
                continue;
            }

            return [
                'literal' => $argument->value->value,
                'range' => $range,
            ];
        }

        return null;
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return array<string, string>
     */
    private function parameterDefaults(array $chain, MethodCall|StaticCall $creator): array
    {
        $defaults = [];
        $seenCreator = false;

        foreach (array_reverse($chain) as $call) {
            if ($seenCreator === false) {
                if ($call === $creator) {
                    $seenCreator = true;
                }

                continue;
            }

            if (!$call instanceof MethodCall || $this->callName($call) !== 'defaults') {
                continue;
            }

            $callDefaults = $this->literalDefaultMap($call);

            if ($callDefaults === null) {
                continue;
            }

            foreach ($callDefaults as $name => $value) {
                $defaults[$name] = $value;
            }
        }

        ksort($defaults);

        return $defaults;
    }

    /**
     * @return array<string, string>|null
     */
    private function literalDefaultMap(MethodCall $call): ?array
    {
        $firstArgument = $call->getArgs()[0] ?? null;
        $secondArgument = $call->getArgs()[1] ?? null;

        if (
            $firstArgument instanceof Arg
            && $secondArgument instanceof Arg
            && $firstArgument->value instanceof String_
        ) {
            $value = $this->literalScalarValue($secondArgument->value);

            if ($firstArgument->value->value === '' || $value === null) {
                return null;
            }

            return [$firstArgument->value->value => $value];
        }

        if (!$firstArgument instanceof Arg || !$firstArgument->value instanceof Array_) {
            return null;
        }

        $defaults = [];

        foreach ($firstArgument->value->items as $item) {
            if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
                return null;
            }

            $value = $this->literalScalarValue($item->value);

            if ($item->key->value === '' || $value === null) {
                return null;
            }

            $defaults[$item->key->value] = $value;
        }

        return $defaults;
    }

    private function literalScalarValue(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof String_ => $expr->value,
            $expr instanceof LNumber, $expr instanceof DNumber => (string) $expr->value,
            $expr instanceof ConstFetch => $this->constFetchLiteralValue($expr),
            default => null,
        };
    }

    private function constFetchLiteralValue(ConstFetch $expr): ?string
    {
        return match (strtolower($expr->name->toString())) {
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            default => null,
        };
    }

    /**
     * @param ?string $controllerContext
     * @return array{class: string, method: string, range: SourceRange, syntax_kind: int}|null
     */
    private function controllerTarget(MethodCall|StaticCall $call, string $contents, ?string $controllerContext): ?array
    {
        $method = $this->callName($call);

        if ($method === null || !isset(self::SIMPLE_CREATORS[$method])) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['action']] ?? null;

        if (!$argument instanceof Arg) {
            return null;
        }

        return $this->targetFromExpression($argument->value, $contents, $controllerContext);
    }

    /**
     * @param ?string $controllerContext
     * @return array{class: string, method: string, range: SourceRange, syntax_kind: int}|null
     */
    private function targetFromExpression(Expr $expr, string $contents, ?string $controllerContext = null): ?array
    {
        if ($expr instanceof ClassConstFetch && $this->isClassConstFetch($expr)) {
            $className = $this->resolvedName($expr->class);
            $range = $this->expressionRange($expr, $contents);

            if ($className === null || $range === null) {
                return null;
            }

            return [
                'class' => $className,
                'method' => '__invoke',
                'range' => $range,
                'syntax_kind' => SyntaxKind::Identifier,
            ];
        }

        if ($expr instanceof Array_ && array_is_list($expr->items) && count($expr->items) === 2) {
            return $this->targetFromArray($expr->items, $contents);
        }

        if ($expr instanceof String_ && str_contains($expr->value, '@')) {
            [$className, $methodName] = explode('@', $expr->value, 2);
            $range = $this->stringRange($expr, $contents);

            if ($className === '' || $methodName === '' || $range === null) {
                return null;
            }

            return [
                'class' => ltrim($className, '\\'),
                'method' => $methodName,
                'range' => $range,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ];
        }

        if ($expr instanceof String_ && $controllerContext !== null && $expr->value !== '') {
            $range = $this->stringRange($expr, $contents);

            if ($range === null) {
                return null;
            }

            return [
                'class' => ltrim($controllerContext, '\\'),
                'method' => $expr->value,
                'range' => $range,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ];
        }

        return null;
    }

    /**
     * @param list<ArrayItem|null> $items
     * @return array{class: string, method: string, range: SourceRange, syntax_kind: int}|null
     */
    private function targetFromArray(array $items, string $contents): ?array
    {
        $classItem = $items[0] ?? null;
        $methodItem = $items[1] ?? null;

        if (!$classItem instanceof ArrayItem || !$methodItem instanceof ArrayItem) {
            return null;
        }

        $className = $this->classNameLiteral($classItem->value);
        $range = $methodItem->value instanceof String_ ? $this->stringRange($methodItem->value, $contents) : null;

        if (
            $className === null
            || !$methodItem->value instanceof String_
            || $methodItem->value->value === ''
            || $range === null
        ) {
            return null;
        }

        return [
            'class' => $className,
            'method' => $methodItem->value->value,
            'range' => $range,
            'syntax_kind' => SyntaxKind::StringLiteralKey,
        ];
    }

    /**
     * @return array{class: string, range: SourceRange, syntax_kind: int}|null
     */
    private function resourceControllerTarget(Expr $expr, string $contents): ?array
    {
        $className = $this->classNameLiteral($expr);
        $range = $this->expressionRange($expr, $contents);

        if ($className === null || $range === null) {
            return null;
        }

        return [
            'class' => $className,
            'range' => $range,
            'syntax_kind' => $expr instanceof String_ ? SyntaxKind::StringLiteralKey : SyntaxKind::Identifier,
        ];
    }

    private function classNameLiteral(Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch && $this->isClassConstFetch($expr)) {
            return $this->resolvedName($expr->class);
        }

        if ($expr instanceof String_ && $expr->value !== '') {
            return ltrim($expr->value, '\\');
        }

        return null;
    }

    private function componentName(MethodCall|StaticCall $call): ?string
    {
        $method = $this->callName($call);

        if ($method === null || !in_array($method, ['livewire', 'route'], true)) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['action']] ?? null;

        if (!$argument instanceof Arg || !$argument->value instanceof String_) {
            return null;
        }

        $literal = $argument->value->value;

        if ($literal === '' || str_contains($literal, '@')) {
            return null;
        }

        return $literal;
    }

    private function viewName(MethodCall|StaticCall $call): ?string
    {
        return $this->simpleActionLiteral($call, 'view');
    }

    private function redirectTarget(MethodCall|StaticCall $call): ?string
    {
        $method = $this->callName($call);

        if ($method === null || !in_array($method, ['redirect', 'permanentredirect'], true)) {
            return null;
        }

        return $this->simpleActionLiteral($call, $method);
    }

    private function simpleActionLiteral(MethodCall|StaticCall $call, string $expectedMethod): ?string
    {
        $method = $this->callName($call);

        if ($method !== $expectedMethod || !isset(self::SIMPLE_CREATORS[$method])) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['action']] ?? null;

        if (!$argument instanceof Arg || !$argument->value instanceof String_ || $argument->value->value === '') {
            return null;
        }

        return $argument->value->value;
    }

    private function uriLiteral(MethodCall|StaticCall $call): ?string
    {
        $method = $this->callName($call);

        if ($method === null || !isset(self::SIMPLE_CREATORS[$method])) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['uri']] ?? null;

        if (!$argument instanceof Arg || !$argument->value instanceof String_) {
            return null;
        }

        return $argument->value->value !== '' ? $argument->value->value : null;
    }

    private function creatorAnchorRange(MethodCall|StaticCall $call, string $contents): ?SourceRange
    {
        $method = $this->callName($call);

        if ($method === null) {
            return null;
        }

        if (isset(self::RESOURCE_CREATORS[$method])) {
            $argument = $call->getArgs()[self::RESOURCE_CREATORS[$method]['name']] ?? null;

            return $argument instanceof Arg && $argument->value instanceof String_
                ? $this->stringRange($argument->value, $contents)
                : null;
        }

        if (!isset(self::SIMPLE_CREATORS[$method])) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['uri']] ?? null;

        return $argument instanceof Arg && $argument->value instanceof String_
            ? $this->stringRange($argument->value, $contents)
            : null;
    }

    private function actionRange(MethodCall|StaticCall $call, string $contents): ?SourceRange
    {
        $method = $this->callName($call);

        if ($method === null) {
            return null;
        }

        if (isset(self::RESOURCE_CREATORS[$method])) {
            $argument = $call->getArgs()[self::RESOURCE_CREATORS[$method]['controller']] ?? null;

            return $argument instanceof Arg ? $this->expressionRange($argument->value, $contents) : null;
        }

        if (!isset(self::SIMPLE_CREATORS[$method])) {
            return null;
        }

        $argument = $call->getArgs()[self::SIMPLE_CREATORS[$method]['action']] ?? null;

        return $argument instanceof Arg ? $this->expressionRange($argument->value, $contents) : null;
    }

    private function prefixedUri(?string $prefix, ?string $uri): ?string
    {
        $prefix = trim((string) $prefix);
        $uri = trim((string) $uri);

        if ($prefix === '' && $uri === '') {
            return null;
        }

        $prefix = trim($prefix, '/');
        $uri = trim($uri, '/');

        if ($prefix === '') {
            return '/' . $uri;
        }

        if ($uri === '') {
            return '/' . $prefix;
        }

        return '/' . $prefix . '/' . $uri;
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

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Support\PhpRouteDeclaration;
use Bnomei\ScipLaravel\Support\PhpRouteDeclarationFinder;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Illuminate\Routing\Router;
use Laravel\Ranger\Components\Model as RangerModel;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ExprClosure;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

use function array_keys;
use function array_values;
use function count;
use function is_string;
use function ksort;
use function ltrim;
use function preg_match_all;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;

final class RouteBoundModelScopeInventoryBuilder
{
    /**
     * @var array<string, RouteBoundModelScopeInventory>
     */
    private static array $inventoryCache = [];

    public function __construct(
        private readonly PhpRouteDeclarationFinder $routeDeclarationFinder = new PhpRouteDeclarationFinder(),
        private readonly LivewireComponentInventoryBuilder $componentInventoryBuilder = new LivewireComponentInventoryBuilder(),
        private readonly \Bnomei\ScipLaravel\Support\RouteDepthExtractor $routeDepthExtractor = new \Bnomei\ScipLaravel\Support\RouteDepthExtractor(),
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public static function reset(): void
    {
        self::$inventoryCache = [];
    }

    public function collect(LaravelContext $context): RouteBoundModelScopeInventory
    {
        $knownModels = [];

        foreach ($context->rangerSnapshot->models as $model) {
            if ($model instanceof RangerModel && $model->name !== '') {
                $knownModels[$model->name] = true;
            }
        }

        if ($knownModels === []) {
            return new RouteBoundModelScopeInventory();
        }

        $cacheKey = $this->cacheKey($context->projectRoot, $knownModels);

        if (isset(self::$inventoryCache[$cacheKey])) {
            return self::$inventoryCache[$cacheKey];
        }

        $componentInventory = $this->componentInventoryBuilder->collect($context);
        $explicitBindings = $this->explicitBindingsByParameter($context, $knownModels);
        $scopes = [];
        $conflicts = [];
        $normalizedAliasCache = [];
        $livewireComponentClassCache = [];
        $literalParameterCache = [];

        foreach ($this->routeDeclarationFinder->find($context->projectRoot) as $declaration) {
            $component = $this->componentContextForDeclaration(
                $componentInventory,
                $declaration,
                $normalizedAliasCache,
                $livewireComponentClassCache,
            );

            if (!$component instanceof LivewireComponentContext) {
                continue;
            }

            foreach ($this->literalRouteParameters(
                $declaration->uriLiteral,
                $literalParameterCache,
            ) as $parameterName) {
                $modelClass = $this->boundModelClass($component, $parameterName, $knownModels, $explicitBindings);

                if ($modelClass === null || isset($conflicts[$component->documentPath][$parameterName])) {
                    continue;
                }

                if (
                    isset($scopes[$component->documentPath][$parameterName])
                    && $scopes[$component->documentPath][$parameterName] !== $modelClass
                ) {
                    unset($scopes[$component->documentPath][$parameterName]);
                    $conflicts[$component->documentPath][$parameterName] = true;

                    continue;
                }

                $scopes[$component->documentPath][$parameterName] = $modelClass;
            }
        }

        ksort($scopes);

        foreach ($scopes as &$scope) {
            ksort($scope);
        }

        unset($scope);

        return self::$inventoryCache[$cacheKey] = new RouteBoundModelScopeInventory($scopes);
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function cacheKey(string $projectRoot, array $knownModels): string
    {
        $classNames = array_keys($knownModels);
        sort($classNames);

        return $projectRoot . "\0" . implode("\0", $classNames);
    }

    private function componentContextForDeclaration(
        LivewireComponentInventory $inventory,
        PhpRouteDeclaration $declaration,
        array &$normalizedAliasCache,
        array &$livewireComponentClassCache,
    ): ?LivewireComponentContext {
        if ($declaration->componentName !== null) {
            $normalizedAlias =
                $normalizedAliasCache[$declaration->componentName] ??= $this->normalizedComponentAlias($declaration->componentName);

            return $inventory->forAlias($normalizedAlias);
        }

        if (
            $declaration->controllerClass === null
            || !($livewireComponentClassCache[$declaration->controllerClass] ??= $this->isLivewireComponentClass($declaration->controllerClass))
        ) {
            return null;
        }

        return $inventory->forClassName(ltrim($declaration->controllerClass, '\\'));
    }

    /**
     * @param array<string, true> $knownModels
     * @param array<string, string> $explicitBindings
     */
    private function boundModelClass(
        LivewireComponentContext $component,
        string $parameterName,
        array $knownModels,
        array $explicitBindings = [],
    ): ?string {
        $candidates = [];

        foreach ([
            $component->propertyTypes[$parameterName] ?? null,
            $component->mountParameterTypes[$parameterName] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && isset($knownModels[$candidate])) {
                $candidates[$candidate] = true;
            }
        }

        $explicit = $explicitBindings[$parameterName] ?? null;

        if (is_string($explicit) && isset($knownModels[$explicit])) {
            $candidates[$explicit] = true;
        }

        return count($candidates) === 1 ? array_values(array_keys($candidates))[0] : null;
    }

    /**
     * @param array<string, true> $knownModels
     * @return array<string, string>
     */
    private function explicitBindingsByParameter(LaravelContext $context, array $knownModels): array
    {
        $bindings = $this->runtimeExplicitBindingsByParameter($context, $knownModels);

        if (is_array($bindings)) {
            ksort($bindings);

            return $bindings;
        }

        $bindings = [];

        foreach ($this->routeDepthExtractor->explicitBindings($context->projectRoot) as $binding) {
            if (!isset($knownModels[$binding->className])) {
                continue;
            }

            $bindings[$binding->parameter] = $binding->className;
        }

        ksort($bindings);

        return $bindings;
    }

    /**
     * @return list<string>
     */
    private function literalRouteParameters(?string $uri, array &$cache): array
    {
        if (!is_string($uri) || $uri === '') {
            return [];
        }

        if (isset($cache[$uri])) {
            return $cache[$uri];
        }

        $matches = [];
        preg_match_all('/\{(?<parameter>[^}]+)\}/', $uri, $matches);
        $parameters = [];

        foreach ($matches['parameter'] ?? [] as $parameter) {
            if (!is_string($parameter) || $parameter === '' || str_contains($parameter, ':')) {
                continue;
            }

            $parameter = str_replace('?', '', $parameter);

            if ($parameter === '' || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $parameter)) {
                continue;
            }

            $parameters[$parameter] = true;
        }

        $parameters = array_keys($parameters);
        sort($parameters);

        return $cache[$uri] = $parameters;
    }

    private function normalizedComponentAlias(string $componentName): string
    {
        $componentName = ltrim($componentName, '\\');
        $componentName = str_replace(['/', '\\'], '.', $componentName);

        if (str_starts_with($componentName, 'livewire.')) {
            return substr($componentName, strlen('livewire.'));
        }

        return $componentName;
    }

    private function isLivewireComponentClass(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable) {
            return false;
        }

        return $reflection->isSubclassOf('Livewire\\Component');
    }

    /**
     * Prefer the booted router state over a full project scan. Laravel has already
     * materialized explicit binders at this point, so we can usually recover the
     * target model class directly from the registered callback.
     *
     * @param array<string, true> $knownModels
     * @return ?array<string, string>
     */
    private function runtimeExplicitBindingsByParameter(LaravelContext $context, array $knownModels): ?array
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $router = $context->application->make(Router::class);
        } catch (Throwable) {
            return null;
        }

        if (!$router instanceof Router) {
            return null;
        }

        try {
            $routerReflection = new ReflectionClass($router);
            $bindersProperty = $routerReflection->getProperty('binders');
            $bindersProperty->setAccessible(true);
            $binders = $bindersProperty->getValue($router);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($binders) || $binders === []) {
            return [];
        }

        $bindings = [];

        foreach ($binders as $parameter => $binder) {
            if (!is_string($parameter) || !$binder instanceof \Closure) {
                continue;
            }

            $modelClass = $this->modelClassFromRuntimeBinder($binder, $knownModels);

            if ($modelClass !== null) {
                $bindings[$parameter] = $modelClass;
            }
        }

        return $bindings;
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function modelClassFromRuntimeBinder(\Closure $binder, array $knownModels): ?string
    {
        try {
            $reflection = new ReflectionFunction($binder);
        } catch (Throwable) {
            return null;
        }

        $staticVariables = $reflection->getStaticVariables();
        $modelClass = $staticVariables['class'] ?? null;

        if (is_string($modelClass) && isset($knownModels[$modelClass])) {
            return $modelClass;
        }

        return $this->modelClassFromBindingClosure($reflection, $knownModels);
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function modelClassFromBindingClosure(ReflectionFunction $reflection, array $knownModels): ?string
    {
        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($ast === null) {
            return null;
        }

        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $node = $this->nodeFinder->findFirst($ast, static function (Node $node) use ($startLine, $endLine): bool {
            return (
                ($node instanceof ExprClosure || $node instanceof ArrowFunction)
                && $node->getStartLine() === $startLine
                && $node->getEndLine() === $endLine
            );
        });

        if (!$node instanceof ExprClosure && !$node instanceof ArrowFunction) {
            return null;
        }

        $target = $node instanceof ArrowFunction ? $node->expr : $this->closureReturnExpression($node);

        if (!$target instanceof Node\Expr) {
            return null;
        }

        $className = $this->bindingCallbackClassName($target);

        return is_string($className) && isset($knownModels[$className]) ? $className : null;
    }

    private function closureReturnExpression(ExprClosure $closure): ?Node\Expr
    {
        foreach ($closure->stmts as $statement) {
            if ($statement instanceof Return_ && $statement->expr instanceof Node\Expr) {
                return $statement->expr;
            }
        }

        return null;
    }

    private function bindingCallbackClassName(Node\Expr $expr): ?string
    {
        if ($expr instanceof StaticCall && $expr->class instanceof Name) {
            return $this->normalizedName($expr->class);
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            return $this->normalizedName($expr->class);
        }

        if (
            $expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return $this->normalizedName($expr->class);
        }

        return null;
    }

    private function normalizedName(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        $value = ltrim($name->toString(), '\\');

        return $value !== '' ? $value : null;
    }
}

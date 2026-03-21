<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Illuminate\View\Component;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use Throwable;

use function array_keys;
use function array_values;
use function count;
use function in_array;
use function is_dir;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function preg_match;
use function preg_replace;
use function realpath;
use function sort;
use function spl_object_id;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;

final class BladeClassComponentInventoryBuilder
{
    /**
     * @var array<string, BladeClassComponentInventory>
     */
    private static array $inventoryCache = [];

    public static function reset(): void
    {
        self::$inventoryCache = [];
    }

    private NodeFinder $nodeFinder;

    public function __construct(
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        ?ProjectPhpAnalysisCache $analysisCache = null,
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function collect(LaravelContext $context): BladeClassComponentInventory
    {
        $cacheKey = $this->cacheKey($context);

        if (isset(self::$inventoryCache[$cacheKey])) {
            return self::$inventoryCache[$cacheKey];
        }

        $finder = $this->viewFinder($context);
        $root = $context->projectRoot . '/app/View/Components';

        if (!is_dir($root)) {
            return self::$inventoryCache[$cacheKey] = new BladeClassComponentInventory([], []);
        }

        $contextsByClass = [];

        foreach ($this->analysisCache->phpFilesInRoots([$root]) as $filePath) {
            $payload = $this->classComponentPayload($context, $finder, $root, $filePath);

            if ($payload === null) {
                continue;
            }

            $contextsByClass[$payload->className] = $payload;
        }

        if ($contextsByClass === []) {
            return self::$inventoryCache[$cacheKey] = new BladeClassComponentInventory([], []);
        }

        $contextsByAlias = [];
        $contextsByDocumentPath = [];

        foreach ($contextsByClass as $contextPayload) {
            foreach ($contextPayload->aliases as $alias) {
                $contextsByAlias[$alias] = $contextPayload;
            }

            if ($contextPayload->documentPath !== null) {
                $contextsByDocumentPath[$contextPayload->documentPath] = $contextPayload;
            }
        }

        foreach ($this->manualAliases($context) as $alias => $className) {
            $componentContext = $contextsByClass[$className] ?? null;

            if ($componentContext instanceof BladeClassComponentContext) {
                $contextsByAlias[$alias] = $componentContext;
            }
        }

        ksort($contextsByAlias);
        ksort($contextsByDocumentPath);

        return self::$inventoryCache[$cacheKey] = new BladeClassComponentInventory(
            $contextsByAlias,
            $contextsByDocumentPath,
        );
    }

    private function cacheKey(LaravelContext $context): string
    {
        return (
            $context->projectRoot
            . "\x1F"
            . spl_object_id($context->application)
            . "\x1F"
            . spl_object_id($context->baselineIndex)
        );
    }

    private function classComponentPayload(
        LaravelContext $context,
        ?object $finder,
        string $root,
        string $filePath,
    ): ?BladeClassComponentContext {
        $contents = $this->analysisCache->contents($filePath);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($ast === null) {
            return null;
        }
        $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);

        if (!$class instanceof Class_) {
            return null;
        }

        $className = $this->resolvedClassName($class);

        if ($className === null || !$this->isBladeComponent($className)) {
            return null;
        }

        $documentPath = null;
        $viewName = $this->renderViewName($class);

        if ($finder !== null && $viewName !== null) {
            $viewPath = $this->resolveViewPath($finder, $viewName);

            if (
                is_string($viewPath)
                && $this->isLocalProjectPath($context, $viewPath)
                && str_ends_with($viewPath, '.blade.php')
            ) {
                $documentPath = $context->relativeProjectPath($viewPath);
            }
        }

        $relativeClassPath = $context->relativeProjectPath($filePath);
        $classSymbol = $this->classSymbolResolver->resolve(
            $context->baselineIndex,
            $relativeClassPath,
            $className,
            $class->getStartLine(),
        );

        if (!is_string($classSymbol) || $classSymbol === '') {
            return null;
        }

        $propertySymbols = [];

        foreach ($class->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                $symbol = $this->propertySymbolResolver->resolve(
                    $context->baselineIndex,
                    $relativeClassPath,
                    $className,
                    $name,
                );

                if (is_string($symbol) && $symbol !== '') {
                    $propertySymbols[$name] = $symbol;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }

            foreach ($method->getParams() as $parameter) {
                if (!$parameter->isPromoted() || !$parameter->isPublic()) {
                    continue;
                }

                $name = is_string($parameter->var->name) ? $parameter->var->name : '';

                if ($name === '' || isset($propertySymbols[$name])) {
                    continue;
                }

                $symbol = $this->propertySymbolResolver->resolve(
                    $context->baselineIndex,
                    $relativeClassPath,
                    $className,
                    $name,
                );

                if (is_string($symbol) && $symbol !== '') {
                    $propertySymbols[$name] = $symbol;
                }
            }
        }

        ksort($propertySymbols);

        return new BladeClassComponentContext(
            className: $className,
            classSymbol: $classSymbol,
            propertySymbols: $propertySymbols,
            documentPath: $documentPath,
            aliases: [$this->conventionalAlias($root, $filePath)],
        );
    }

    /**
     * @return array<string, string>
     */
    private function manualAliases(LaravelContext $context): array
    {
        $compiler = $this->bladeCompiler($context);

        if ($compiler === null || !method_exists($compiler, 'getClassComponentAliases')) {
            return [];
        }

        $aliases = $compiler->getClassComponentAliases();

        if (!is_array($aliases)) {
            return [];
        }

        $resolved = [];

        foreach ($aliases as $alias => $className) {
            if (!is_string($alias) || $alias === '' || !is_string($className) || $className === '') {
                continue;
            }

            $resolved[$alias] = ltrim($className, '\\');
        }

        ksort($resolved);

        return $resolved;
    }

    private function bladeCompiler(LaravelContext $context): ?object
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $compiler = $context->application->make('blade.compiler');
        } catch (Throwable) {
            return null;
        }

        return is_object($compiler) ? $compiler : null;
    }

    private function viewFinder(LaravelContext $context): ?object
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $factory = $context->application->make('view');
        } catch (Throwable) {
            return null;
        }

        if (!is_object($factory) || !method_exists($factory, 'getFinder')) {
            return null;
        }

        $finder = $factory->getFinder();

        return is_object($finder) ? $finder : null;
    }

    private function resolveViewPath(object $finder, string $name): ?string
    {
        if (!method_exists($finder, 'find')) {
            return null;
        }

        try {
            $path = $finder->find($name);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? (realpath($path) ?: $path) : null;
    }

    private function renderViewName(Class_ $class): ?string
    {
        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== 'render') {
                continue;
            }

            foreach ((array) $method->stmts as $statement) {
                foreach ($this->nodeFinder->findInstanceOf([$statement], Node\Stmt\Return_::class) as $return) {
                    $expr = $return->expr ?? null;

                    if ($expr instanceof String_) {
                        return $expr->value;
                    }

                    if (
                        $expr instanceof FuncCall
                        && $expr->name instanceof Name
                        && strtolower($expr->name->toString()) === 'view'
                    ) {
                        $argument = $expr->getArgs()[0] ?? null;

                        if ($argument?->value instanceof String_) {
                            return $argument->value->value;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        $namespacedName = $class->namespacedName ?? null;

        return $namespacedName instanceof Name ? ltrim($namespacedName->toString(), '\\') : null;
    }

    private function isBladeComponent(string $className): bool
    {
        return is_subclass_of($className, Component::class);
    }

    private function conventionalAlias(string $root, string $filePath): string
    {
        $relativePath = substr($filePath, strlen($root) + 1);
        $name = str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);
        $name = str_ends_with($name, '.php') ? substr($name, 0, -4) : $name;
        $segments = array_map(
            fn(string $segment): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $segment)),
            explode('.', $name),
        );

        return implode('.', $segments);
    }

    private function isLocalProjectPath(LaravelContext $context, string $path): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedRoot = realpath($context->projectRoot) ?: $context->projectRoot;
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return (
            ($resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, $rootPrefix))
            && !str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
        );
    }
}

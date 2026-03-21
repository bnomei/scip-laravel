<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Blade\VoltBladePreambleParser;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use ReflectionClass;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function preg_replace;
use function realpath;
use function sort;
use function spl_object_id;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

final class LivewireComponentInventoryBuilder
{
    /**
     * @var array<string, LivewireComponentInventory>
     */
    private static array $inventoryCache = [];

    public static function reset(): void
    {
        self::$inventoryCache = [];
    }

    private NodeFinder $nodeFinder;

    public function __construct(
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly VoltBladePreambleParser $voltPreambleParser = new VoltBladePreambleParser(),
        ?ProjectPhpAnalysisCache $analysisCache = null,
        ?BladeRuntimeCache $bladeCache = null,
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    private readonly BladeRuntimeCache $bladeCache;

    public function collect(LaravelContext $context): LivewireComponentInventory
    {
        $cacheKey = $this->cacheKey($context);

        if (isset(self::$inventoryCache[$cacheKey])) {
            return self::$inventoryCache[$cacheKey];
        }

        $contexts = [];
        $finder = $this->viewFinder($context);

        foreach ($this->classBackedContexts($context, $finder) as $documentPath => $componentContext) {
            $contexts[$documentPath] = $componentContext;
        }

        foreach ($this->voltContexts($context) as $documentPath => $componentContext) {
            $contexts[$documentPath] = $componentContext;
        }

        foreach ($this->anonymousBladeContexts($context) as $documentPath => $componentContext) {
            if (!isset($contexts[$documentPath])) {
                $contexts[$documentPath] = $componentContext;
                continue;
            }

            $existing = $contexts[$documentPath];
            $contexts[$documentPath] = new LivewireComponentContext(
                documentPath: $existing->documentPath,
                propertySymbols: $existing->propertySymbols,
                methodSymbols: $existing->methodSymbols,
                propertyTypes: $existing->propertyTypes,
                mountParameterTypes: $existing->mountParameterTypes,
                modelableProperties: $existing->modelableProperties,
                reactiveProperties: $existing->reactiveProperties,
                componentClassName: $existing->componentClassName,
                componentAliases: $this->mergedAliases(
                    $existing->componentAliases,
                    $componentContext->componentAliases,
                ),
                symbolPatches: $existing->symbolPatches,
                definitionPatches: $existing->definitionPatches,
            );
        }

        ksort($contexts);

        return self::$inventoryCache[$cacheKey] = new LivewireComponentInventory($contexts);
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

    /**
     * @return array<string, LivewireComponentContext>
     */
    private function classBackedContexts(LaravelContext $context, ?object $finder): array
    {
        $root = $context->projectRoot . '/app/Livewire';

        if (!is_dir($root)) {
            return [];
        }

        $contexts = [];

        foreach ($this->analysisCache->phpFilesInRoots([$root]) as $filePath) {
            $payload = $this->classBackedPayload($context, $finder, $filePath);

            if ($payload === null) {
                continue;
            }

            $contexts[$payload['documentPath']] = new LivewireComponentContext(
                documentPath: $payload['documentPath'],
                propertySymbols: $payload['propertySymbols'],
                methodSymbols: $payload['methodSymbols'],
                propertyTypes: $payload['propertyTypes'],
                mountParameterTypes: $payload['mountParameterTypes'],
                modelableProperties: $payload['modelableProperties'],
                reactiveProperties: $payload['reactiveProperties'],
                componentClassName: $payload['componentClassName'],
                componentAliases: $payload['componentAliases'],
            );
        }

        return $contexts;
    }

    /**
     * @return ?array{
     *     documentPath: string,
     *     propertySymbols: array<string, string>,
     *     methodSymbols: array<string, string>,
     *     propertyTypes: array<string, string>,
     *     mountParameterTypes: array<string, string>,
     *     modelableProperties: list<string>,
     *     reactiveProperties: list<string>,
     *     componentClassName: string,
     *     componentAliases: list<string>
     * }
     */
    private function classBackedPayload(LaravelContext $context, ?object $finder, string $filePath): ?array
    {
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

        if ($className === null || !$this->isLivewireComponent($className)) {
            return null;
        }

        $viewName = $this->explicitViewName($class) ?? $this->conventionalViewName($className);

        if ($viewName === null || $finder === null) {
            return null;
        }

        $viewPath = $this->resolveViewPath($finder, $viewName);

        if (
            !is_string($viewPath)
            || !$this->isProjectPath($context, $viewPath)
            || !str_ends_with($viewPath, '.blade.php')
        ) {
            return null;
        }

        $relativeClassPath = $context->relativeProjectPath($filePath);
        $documentPath = $context->relativeProjectPath($viewPath);
        $propertySymbols = [];
        $methodSymbols = [];
        $propertyTypes = [];
        $mountParameterTypes = [];
        $modelableProperties = [];
        $reactiveProperties = [];

        foreach ($class->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $propertyName = $prop->name->toString();

                if ($propertyName === '') {
                    continue;
                }

                $typeName = $this->resolvedTypeName($property->type);

                if ($typeName !== null) {
                    $propertyTypes[$propertyName] = $typeName;
                }

                $attributeNames = $this->attributeNames($property->attrGroups);

                if (in_array('livewire\\attributes\\modelable', $attributeNames, true)) {
                    $modelableProperties[] = $propertyName;
                }

                if (in_array('livewire\\attributes\\reactive', $attributeNames, true)) {
                    $reactiveProperties[] = $propertyName;
                }

                $symbol = $this->propertySymbolResolver->resolve(
                    $context->baselineIndex,
                    $relativeClassPath,
                    $className,
                    $propertyName,
                );

                if (is_string($symbol) && $symbol !== '') {
                    $propertySymbols[$propertyName] = $symbol;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            $methodName = $method->name->toString();

            if ($methodName === '') {
                continue;
            }

            $symbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $relativeClassPath,
                $methodName,
                $method->getStartLine(),
            );

            if (is_string($symbol) && $symbol !== '') {
                $methodSymbols[$methodName] = $symbol;
            }

            if (strtolower($methodName) === 'mount') {
                foreach ($method->getParams() as $parameter) {
                    if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                        continue;
                    }

                    $typeName = $this->resolvedTypeName($parameter->type);

                    if ($typeName !== null) {
                        $mountParameterTypes[$parameter->var->name] = $typeName;
                    }
                }
            }
        }

        if ($propertySymbols === [] && $methodSymbols === []) {
            return null;
        }

        ksort($propertyTypes);
        ksort($mountParameterTypes);
        ksort($propertySymbols);
        ksort($methodSymbols);
        sort($modelableProperties);
        sort($reactiveProperties);

        return [
            'documentPath' => $documentPath,
            'propertySymbols' => $propertySymbols,
            'methodSymbols' => $methodSymbols,
            'propertyTypes' => $propertyTypes,
            'mountParameterTypes' => $mountParameterTypes,
            'modelableProperties' => array_values(array_unique($modelableProperties)),
            'reactiveProperties' => array_values(array_unique($reactiveProperties)),
            'componentClassName' => $className,
            'componentAliases' => $this->componentAliases($className, $documentPath),
        ];
    }

    /**
     * @return array<string, LivewireComponentContext>
     */
    private function voltContexts(LaravelContext $context): array
    {
        $contexts = [];

        foreach ($this->bladeCache->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $preamble = $this->voltPreambleParser->parse($contents);

            if ($preamble === null) {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);
            $propertySymbols = [];
            $methodSymbols = [];
            $symbolPatches = [];
            $definitionPatches = [];

            foreach ($preamble->propertyRanges as $name => $range) {
                $symbol = $this->localSymbol('property', $name);
                $propertySymbols[$name] = $symbol;
                $symbolPatches[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'display_name' => $name,
                    'kind' => Kind::Property,
                ]));
                $definitionPatches[] =
                    new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                        'range' => $range->toScipRange(),
                        'symbol' => $symbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => SyntaxKind::Identifier,
                    ]));
            }

            foreach ($preamble->methodRanges as $name => $range) {
                $symbol = $this->localSymbol('method', $name);
                $methodSymbols[$name] = $symbol;
                $symbolPatches[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $symbol,
                    'display_name' => $name,
                    'kind' => Kind::Method,
                ]));
                $definitionPatches[] =
                    new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                        'range' => $range->toScipRange(),
                        'symbol' => $symbol,
                        'symbol_roles' => SymbolRole::Definition,
                        'syntax_kind' => SyntaxKind::Identifier,
                    ]));
            }

            if ($propertySymbols === [] && $methodSymbols === []) {
                continue;
            }

            ksort($propertySymbols);
            ksort($methodSymbols);

            $contexts[$documentPath] = new LivewireComponentContext(
                documentPath: $documentPath,
                propertySymbols: $propertySymbols,
                methodSymbols: $methodSymbols,
                propertyTypes: $preamble->propertyTypes,
                mountParameterTypes: $preamble->mountParameterTypes,
                modelableProperties: [],
                reactiveProperties: [],
                componentAliases: $this->voltComponentAliases($documentPath),
                symbolPatches: $symbolPatches,
                definitionPatches: $definitionPatches,
            );
        }

        return $contexts;
    }

    /**
     * @return array<string, LivewireComponentContext>
     */
    private function anonymousBladeContexts(LaravelContext $context): array
    {
        $root = $context->projectPath('resources', 'views', 'components');

        if (!is_dir($root)) {
            return [];
        }

        $contexts = [];

        foreach ($this->bladeCache->bladeFiles($root) as $filePath) {
            $documentPath = $context->relativeProjectPath($filePath);
            $aliases = $this->anonymousBladeComponentAliases($documentPath);

            if ($aliases === []) {
                continue;
            }

            $contexts[$documentPath] = new LivewireComponentContext(
                documentPath: $documentPath,
                propertySymbols: [],
                methodSymbols: [],
                modelableProperties: [],
                reactiveProperties: [],
                componentAliases: $aliases,
            );
        }

        ksort($contexts);

        return $contexts;
    }

    private function localSymbol(string $kind, string $name): string
    {
        $localId = strtolower($kind . '-' . (preg_replace('/[^A-Za-z0-9_$+\-]+/', '-', $name) ?? $name));

        return 'local livewire-' . $localId;
    }

    private function resolvedTypeName(Node|string|null $type): ?string
    {
        if ($type instanceof Name) {
            $resolved = $type->getAttribute('resolvedName');
            $className = $resolved instanceof Name ? $resolved->toString() : $type->toString();
            $className = ltrim($className, '\\');

            return $className !== '' ? $className : null;
        }

        if ($type instanceof NullableType) {
            return $this->resolvedTypeName($type->type);
        }

        if ($type instanceof UnionType) {
            $resolvedTypes = [];

            foreach ($type->types as $innerType) {
                $resolved = $this->resolvedTypeName($innerType);

                if ($resolved !== null) {
                    $resolvedTypes[$resolved] = true;
                    continue;
                }

                if ($innerType instanceof Identifier && strtolower($innerType->toString()) === 'null') {
                    continue;
                }

                return null;
            }

            return count($resolvedTypes) === 1 ? array_keys($resolvedTypes)[0] : null;
        }

        return null;
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     * @return list<string>
     */
    private function attributeNames(array $groups): array
    {
        $names = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$attribute->name instanceof Name) {
                    continue;
                }

                $name = ltrim($attribute->name->toString(), '\\');

                if ($name !== '') {
                    $names[] = strtolower($name);
                }
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function componentAliases(string $className, string $documentPath): array
    {
        $aliases = [];
        $classAlias = $this->componentAliasFromClassName($className);

        if ($classAlias !== null) {
            $aliases[$classAlias] = true;
        }

        foreach ($this->voltComponentAliases($documentPath) as $alias) {
            $aliases[$alias] = true;
        }

        $aliases = array_keys($aliases);
        sort($aliases);

        return $aliases;
    }

    private function componentAliasFromClassName(string $className): ?string
    {
        $prefix = 'App\\Livewire\\';

        if (!str_starts_with($className, $prefix)) {
            return null;
        }

        $suffix = substr($className, strlen($prefix));

        if ($suffix === '') {
            return null;
        }

        $segments = array_map(
            fn(string $segment): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $segment) ?? $segment),
            explode('\\', $suffix),
        );

        return implode('.', $segments);
    }

    /**
     * @return list<string>
     */
    private function voltComponentAliases(string $documentPath): array
    {
        $prefix = 'resources/views/livewire/';

        if (!str_starts_with($documentPath, $prefix) || !str_ends_with($documentPath, '.blade.php')) {
            return [];
        }

        $relative = substr($documentPath, strlen($prefix), -strlen('.blade.php'));

        if ($relative === '') {
            return [];
        }

        return [str_replace('/', '.', $relative)];
    }

    /**
     * @return list<string>
     */
    private function anonymousBladeComponentAliases(string $documentPath): array
    {
        $prefix = 'resources/views/components/';

        if (!str_starts_with($documentPath, $prefix) || !str_ends_with($documentPath, '.blade.php')) {
            return [];
        }

        $relative = substr($documentPath, strlen($prefix), -strlen('.blade.php'));
        $separatorOffset = strrpos($relative, '/');
        $basename = $separatorOffset === false ? $relative : substr($relative, $separatorOffset + 1);

        if ($basename === '' || !str_starts_with($basename, '⚡')) {
            return [];
        }

        $normalizedBasename = substr($basename, strlen('⚡'));

        if ($normalizedBasename === '') {
            return [];
        }

        $normalizedRelative = $separatorOffset === false
            ? $normalizedBasename
            : substr($relative, 0, $separatorOffset + 1) . $normalizedBasename;

        return [str_replace('/', '.', $normalizedRelative)];
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function mergedAliases(array $left, array $right): array
    {
        $aliases = array_values(array_unique([...$left, ...$right]));
        sort($aliases);

        return $aliases;
    }

    private function explicitViewName(Class_ $class): ?string
    {
        $render = $class->getMethod('render');

        if (!$render instanceof ClassMethod || !is_array($render->stmts)) {
            return null;
        }

        foreach ($this->nodeFinder->findInstanceOf($render->stmts, FuncCall::class) as $call) {
            $functionName = $call->name instanceof Name ? strtolower(ltrim($call->name->toString(), '\\')) : null;

            if ($functionName !== 'view') {
                continue;
            }

            $literal = $call->getArgs()[0]->value ?? null;

            if ($literal instanceof String_ && $literal->value !== '') {
                return $literal->value;
            }
        }

        foreach ($this->nodeFinder->findInstanceOf($render->stmts, StaticCall::class) as $call) {
            $methodName = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
            $className = $call->class instanceof Name ? strtolower(ltrim($call->class->toString(), '\\')) : null;

            if (
                $methodName !== 'make'
                || $className !== 'view' && $className !== 'illuminate\\support\\facades\\view'
            ) {
                continue;
            }

            $literal = $call->getArgs()[0]->value ?? null;

            if ($literal instanceof String_ && $literal->value !== '') {
                return $literal->value;
            }
        }

        return null;
    }

    private function conventionalViewName(string $className): ?string
    {
        $prefix = 'App\\Livewire\\';

        if (!str_starts_with($className, $prefix)) {
            return null;
        }

        $suffix = substr($className, strlen($prefix));

        if ($suffix === '') {
            return null;
        }

        $segments = array_map(
            fn(string $segment): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $segment) ?? $segment),
            explode('\\', $suffix),
        );

        return 'livewire.' . implode('.', $segments);
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        $resolved = $class->namespacedName ?? null;

        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        return null;
    }

    private function isLivewireComponent(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable) {
            return false;
        }

        return $reflection->isSubclassOf('Livewire\\Component');
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

    private function isProjectPath(LaravelContext $context, string $path): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedRoot = realpath($context->projectRoot) ?: $context->projectRoot;
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, $rootPrefix);
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Routes;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Blade\BladeDirectiveScanner;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\ControllerRouteParameterFinder;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\PhpLiteralMethodCallFinder;
use Bnomei\ScipLaravel\Support\PhpRouteDeclarationFinder;
use Bnomei\ScipLaravel\Support\RouteParameterContractInventoryBuilder;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Support\SurveyorTypeFormatter;
use Bnomei\ScipLaravel\Support\TopLevelTypeContractFormatter;
use Bnomei\ScipLaravel\Support\ValidationRuleFormatter;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Illuminate\Support\Reflector;
use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Ranger\Components\JsonResponse;
use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Ranger\Components\Validator as RangerValidator;
use Laravel\Ranger\Support\RouteParameter;
use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Types\Contracts\Type as SurveyorType;
use ReflectionClass;
use ReflectionException;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_key_exists;
use function array_values;
use function count;
use function enum_exists;
use function file_get_contents;
use function get_class;
use function implode;
use function is_dir;
use function is_file;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;

final class RouteEnricher implements Enricher
{
    /**
     * @var array<string, ?ReflectionClass<object>>
     */
    private array $controllerReflectionCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $controllerMethodSymbolCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $controllerDocumentPathCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $routeResponseDocumentationCache = [];

    /**
     * @var array<string, ?string>
     */
    private array $routeValidatorDocumentationCache = [];

    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly PhpLiteralMethodCallFinder $methodCallFinder = new PhpLiteralMethodCallFinder(),
        private readonly BladeDirectiveScanner $bladeScanner = new BladeDirectiveScanner(),
        ?BladeRuntimeCache $bladeCache = null,
        private readonly PhpRouteDeclarationFinder $declarationFinder = new PhpRouteDeclarationFinder(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly ControllerRouteParameterFinder $controllerRouteParameterFinder = new ControllerRouteParameterFinder(),
        private readonly RouteParameterContractInventoryBuilder $routeParameterInventoryBuilder = new RouteParameterContractInventoryBuilder(),
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
        private readonly TopLevelTypeContractFormatter $contractFormatter = new TopLevelTypeContractFormatter(),
        private readonly ValidationRuleFormatter $validationRuleFormatter = new ValidationRuleFormatter(),
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
    }

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $this->controllerReflectionCache = [];
        $this->controllerMethodSymbolCache = [];
        $this->controllerDocumentPathCache = [];
        $this->routeResponseDocumentationCache = [];
        $this->routeValidatorDocumentationCache = [];
        $discoveredRoutes = $this->discoveredRoutes($context);

        if ($discoveredRoutes === []) {
            return IndexPatch::empty();
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $declarations = $this->declarationFinder->find($context->projectRoot);
        $routeParameterInventory = $this->routeParameterInventoryBuilder->build($context);
        $symbolsByName = [];
        $viewSymbolsByName = $this->viewSymbolsByName($context, $normalizer, $declarations);

        foreach ($discoveredRoutes as $name => $_route) {
            $symbolsByName[$name] = $normalizer->route($name);
        }

        $symbols = [];
        $occurrences = [];
        $definitionsByName = [];
        $controllerDocumentationBySymbol = [];
        $analysisCache = [];

        foreach ($this->expandedDeclarations($declarations, $discoveredRoutes) as $declaration) {
            $definitionRange = $declaration->nameRange ?? $declaration->anchorRange;

            if (
                $declaration->nameLiteral !== null
                && $definitionRange !== null
                && array_key_exists($declaration->nameLiteral, $symbolsByName)
            ) {
                $definitionsByName[$declaration->nameLiteral] ??= [];
                $definitionsByName[$declaration->nameLiteral][] = $declaration;
            }

            $declarationViewName = $this->declarationViewName($declaration);

            if (
                $declarationViewName !== null
                && $declaration->targetRange !== null
                && array_key_exists($declarationViewName, $viewSymbolsByName)
            ) {
                $occurrences[] = new DocumentOccurrencePatch(
                    documentPath: $context->relativeProjectPath($declaration->filePath),
                    occurrence: new Occurrence([
                        'range' => $declaration->targetRange->toScipRange(),
                        'symbol' => $viewSymbolsByName[$declarationViewName],
                        'symbol_roles' => SymbolRole::ReadAccess,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                    ]),
                );
            }

            if (
                $declaration->controllerClass === null
                || $declaration->controllerMethod === null
                || $declaration->controllerRange === null
            ) {
                continue;
            }

            $controllerSymbol = $this->controllerMethodSymbol(
                $context,
                $declaration->controllerClass,
                $declaration->controllerMethod,
            );

            if ($controllerSymbol === null) {
                continue;
            }

            if ($declaration->nameLiteral !== null && array_key_exists($declaration->nameLiteral, $discoveredRoutes)) {
                foreach ($this->controllerMethodDocumentation(
                    context: $context,
                    route: $discoveredRoutes[$declaration->nameLiteral],
                    controllerClass: $declaration->controllerClass,
                    controllerMethod: $declaration->controllerMethod,
                    analysisCache: $analysisCache,
                    routeParameterContracts: array_values(
                        $routeParameterInventory['byRouteName'][$declaration->nameLiteral] ?? [],
                    ),
                ) as $documentation) {
                    $controllerDocumentPath = $this->controllerDocumentPath($context, $declaration->controllerClass);

                    if ($controllerDocumentPath === null) {
                        continue;
                    }

                    $controllerDocumentationBySymbol[$controllerSymbol]['documentPath'] = $controllerDocumentPath;
                    $controllerDocumentationBySymbol[$controllerSymbol]['documentation'][] = $documentation;
                }
            }

            foreach ($context->surveyor->methodDocumentation(
                $declaration->controllerClass,
                $declaration->controllerMethod,
            ) as $documentation) {
                $controllerDocumentPath = $this->controllerDocumentPath($context, $declaration->controllerClass);

                if ($controllerDocumentPath === null) {
                    continue;
                }

                $controllerDocumentationBySymbol[$controllerSymbol]['documentPath'] = $controllerDocumentPath;
                $controllerDocumentationBySymbol[$controllerSymbol]['documentation'][] = $documentation;
            }

            $signatureDocumentation = $context->surveyor->methodSignatureDocumentation(
                $declaration->controllerClass,
                $declaration->controllerMethod,
            );

            if ($signatureDocumentation !== null) {
                $controllerDocumentationBySymbol[$controllerSymbol]['signatureDocumentation'] = $signatureDocumentation;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($declaration->filePath),
                occurrence: new Occurrence([
                    'range' => $declaration->controllerRange->toScipRange(),
                    'symbol' => $controllerSymbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => $declaration->controllerSyntaxKind,
                ]),
            );

            if ($declaration->nameLiteral !== null && array_key_exists($declaration->nameLiteral, $discoveredRoutes)) {
                foreach ($this->responseReferenceSymbols(
                    $context,
                    $discoveredRoutes[$declaration->nameLiteral],
                    $analysisCache,
                ) as $responseSymbol) {
                    $occurrences[] = new DocumentOccurrencePatch(
                        documentPath: $context->relativeProjectPath($declaration->filePath),
                        occurrence: new Occurrence([
                            'range' => $declaration->controllerRange->toScipRange(),
                            'symbol' => $responseSymbol,
                            'symbol_roles' => SymbolRole::ReadAccess,
                            'syntax_kind' => $declaration->controllerSyntaxKind,
                        ]),
                    );
                }
            }
        }

        ksort($definitionsByName);

        foreach ($definitionsByName as $name => $definitions) {
            if (count($definitions) !== 1) {
                continue;
            }

            $definition = $definitions[0];
            $definitionRange = $definition->nameRange ?? $definition->anchorRange;

            if ($definitionRange === null) {
                continue;
            }

            $relativePath = $context->relativeProjectPath($definition->filePath);
            $documentation = [];

            if (isset($discoveredRoutes[$name])) {
                $documentation = $this->routeSymbolDocumentation(
                    $context,
                    $discoveredRoutes[$name],
                    $definition,
                    $analysisCache,
                    array_values($routeParameterInventory['byRouteName'][$name] ?? []),
                );
            }

            $symbols[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                'symbol' => $symbolsByName[$name],
                'display_name' => $name,
                'kind' => Kind::Key,
                'documentation' => $documentation,
            ]));
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                'range' => $definitionRange->toScipRange(),
                'symbol' => $symbolsByName[$name],
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));

            foreach ($routeParameterInventory['byRouteName'][$name] ?? [] as $contract) {
                $symbols[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                    'symbol' => $contract->symbol,
                    'display_name' => $contract->parameterName,
                    'kind' => Kind::Parameter,
                    'documentation' => $contract->documentation,
                ]));

                $parameterRange = $definition->anchorRange ?? $definitionRange;

                if ($parameterRange !== null) {
                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                            'range' => $parameterRange->toScipRange(),
                            'symbol' => $contract->symbol,
                            'symbol_roles' => SymbolRole::Definition,
                            'syntax_kind' => SyntaxKind::StringLiteralKey,
                            'override_documentation' => ['Route parameter: ' . $contract->parameterName],
                        ]));
                }
            }

            foreach ($this->routeRuntimeContractPatches(
                context: $context,
                normalizer: $normalizer,
                routeName: $name,
                route: $discoveredRoutes[$name],
                routeSymbol: $symbolsByName[$name],
                definition: $definition,
                analysisCache: $analysisCache,
            ) as $contractPatch) {
                if ($contractPatch instanceof DocumentSymbolPatch) {
                    $symbols[] = $contractPatch;
                    continue;
                }

                $occurrences[] = $contractPatch;
            }
        }

        foreach ($controllerDocumentationBySymbol as $symbol => $payload) {
            $symbolPayload = [
                'symbol' => $symbol,
                'documentation' => $this->normalizedDocumentation($payload['documentation']),
            ];

            if (($payload['signatureDocumentation'] ?? null) !== null) {
                $symbolPayload['signature_documentation'] = $payload['signatureDocumentation'];
            }

            $symbols[] = new DocumentSymbolPatch(
                documentPath: $payload['documentPath'],
                symbol: new SymbolInformation($symbolPayload),
            );
        }

        foreach ($this->callFinder->find($context->projectRoot, ['route', 'to_route']) as $call) {
            if (!array_key_exists($call->literal, $symbolsByName)) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByName[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Laravel route: ' . $call->literal],
                ]),
            );
        }

        foreach ($this->methodCallFinder->find($context->projectRoot, [
            'request' => ['methods' => ['routeIs']],
            'redirect' => ['methods' => ['route']],
        ]) as $call) {
            if (!array_key_exists($call->literal, $symbolsByName)) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($call->filePath),
                occurrence: new Occurrence([
                    'range' => $call->range->toScipRange(),
                    'symbol' => $symbolsByName[$call->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Laravel route: ' . $call->literal],
                ]),
            );
        }

        foreach ($this->controllerRouteParameterFinder->find(
            $routeParameterInventory['byControllerMethod'] ?? [],
        ) as $reference) {
            $controllerKey = $reference->controllerClass . "\n" . $reference->controllerMethod;
            $contract =
                $routeParameterInventory['byControllerMethod'][$controllerKey][$reference->parameterName] ?? null;

            if ($contract === null) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($reference->filePath),
                occurrence: new Occurrence([
                    'range' => $reference->range->toScipRange(),
                    'symbol' => $contract->symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'override_documentation' => ['Route parameter: ' . $reference->parameterName],
                ]),
            );
        }

        foreach ($this->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            foreach ($this->bladeScanner->scanRouteReferences($contents) as $reference) {
                if (!array_key_exists($reference->literal, $symbolsByName)) {
                    continue;
                }

                $occurrences[] = new DocumentOccurrencePatch(
                    documentPath: $context->relativeProjectPath($filePath),
                    occurrence: new Occurrence([
                        'range' => $reference->range->toScipRange(),
                        'symbol' => $symbolsByName[$reference->literal],
                        'symbol_roles' => SymbolRole::ReadAccess,
                        'syntax_kind' => SyntaxKind::StringLiteralKey,
                        'override_documentation' => ['Laravel route: ' . $reference->literal],
                    ]),
                );
            }
        }

        if ($symbols === [] && $occurrences === []) {
            return IndexPatch::empty();
        }

        return new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @return array<string, object>
     */
    private function discoveredRoutes(LaravelContext $context): array
    {
        $routes = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!is_object($route) || !method_exists($route, 'name')) {
                continue;
            }

            $name = $route->name();

            if (is_string($name) && $name !== '') {
                $routes[$name] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }

    private function controllerMethodSymbol(LaravelContext $context, string $className, string $methodName): ?string
    {
        $cacheKey = $className . '::' . $methodName;

        if (array_key_exists($cacheKey, $this->controllerMethodSymbolCache)) {
            return $this->controllerMethodSymbolCache[$cacheKey];
        }

        $reflection = $this->reflectionForClass($className);

        if (!$reflection instanceof ReflectionClass) {
            return $this->controllerMethodSymbolCache[$cacheKey] = null;
        }

        if (!$reflection->hasMethod($methodName)) {
            return $this->controllerMethodSymbolCache[$cacheKey] = null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return $this->controllerMethodSymbolCache[$cacheKey] = null;
        }

        try {
            $lineNumber = $reflection->getMethod($methodName)->getStartLine();
        } catch (Throwable) {
            return $this->controllerMethodSymbolCache[$cacheKey] = null;
        }

        return $this->controllerMethodSymbolCache[$cacheKey] = $this->methodSymbolResolver->resolve(
            $context->baselineIndex,
            $context->relativeProjectPath($filePath),
            $methodName,
            $lineNumber,
        );
    }

    private function controllerDocumentPath(LaravelContext $context, string $className): ?string
    {
        if (array_key_exists($className, $this->controllerDocumentPathCache)) {
            return $this->controllerDocumentPathCache[$className];
        }

        $reflection = $this->reflectionForClass($className);

        if (!$reflection instanceof ReflectionClass) {
            return $this->controllerDocumentPathCache[$className] = null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return $this->controllerDocumentPathCache[$className] = null;
        }

        return $this->controllerDocumentPathCache[$className] = $context->relativeProjectPath($filePath);
    }

    /**
     * @return list<string>
     */
    /**
     * @param array<string, ?ClassResult> $analysisCache
     */
    private function routeSymbolDocumentation(
        LaravelContext $context,
        object $route,
        ?\Bnomei\ScipLaravel\Support\PhpRouteDeclaration $declaration = null,
        array &$analysisCache = [],
        array $routeParameterContracts = [],
    ): array {
        if (!$route instanceof RangerRoute) {
            return [];
        }

        $documentation = [];
        $verbs = $this->routeVerbs($route);

        if ($verbs !== '') {
            $documentation[] = 'Laravel route: ' . $verbs . ' ' . $route->uri();
        }

        if ($route->hasController()) {
            $documentation[] = 'Controller: ' . ltrim($route->controller(), '\\') . '@' . $route->method();
        }

        $parameters = $routeParameterContracts !== []
            ? $this->routeParameterDocumentationFromContracts($routeParameterContracts)
            : $this->routeParameterDocumentation($route);

        if ($parameters !== null) {
            $documentation[] = $parameters;
        }

        $validatorKeys = $this->routeValidatorDocumentation($route->requestValidator());

        if ($validatorKeys !== null) {
            $documentation[] = $validatorKeys;
        }

        $responses = $this->routeResponseDocumentation($route);

        if ($responses !== null) {
            $documentation[] = $responses;
        }

        foreach ($this->responseClassDocumentation($context, $route, $analysisCache) as $line) {
            $documentation[] = $line;
        }

        if ($declaration?->redirectTarget !== null) {
            $documentation[] = 'Redirect target: ' . $declaration->redirectTarget;
        }

        return $this->normalizedDocumentation($documentation);
    }

    /**
     * @param list<\Bnomei\ScipLaravel\Support\PhpRouteDeclaration> $declarations
     * @param array<string, object> $discoveredRoutes
     * @return list<\Bnomei\ScipLaravel\Support\PhpRouteDeclaration>
     */
    private function expandedDeclarations(array $declarations, array $discoveredRoutes): array
    {
        $expanded = [];

        foreach ($declarations as $declaration) {
            $expanded[] = $declaration;

            if (
                $declaration->resourceName === null
                || $declaration->resourceType === null
                || $declaration->controllerClass === null
                || $declaration->controllerRange === null
                || $declaration->anchorRange === null
            ) {
                continue;
            }

            foreach ($this->matchedResourceRoutes($declaration, $discoveredRoutes) as $name => $route) {
                $expanded[] = new \Bnomei\ScipLaravel\Support\PhpRouteDeclaration(
                    filePath: $declaration->filePath,
                    uriLiteral: $route->uri(),
                    nameLiteral: $name,
                    nameRange: null,
                    anchorRange: $declaration->anchorRange,
                    targetRange: $declaration->targetRange,
                    controllerClass: ltrim($route->controller(), '\\'),
                    controllerMethod: $route->method(),
                    controllerRange: $declaration->controllerRange,
                    controllerSyntaxKind: $declaration->controllerSyntaxKind,
                    resourceName: $declaration->resourceName,
                    resourceType: $declaration->resourceType,
                );
            }
        }

        return $expanded;
    }

    /**
     * @param array<string, object> $discoveredRoutes
     * @return array<string, RangerRoute>
     */
    private function matchedResourceRoutes(
        \Bnomei\ScipLaravel\Support\PhpRouteDeclaration $declaration,
        array $discoveredRoutes,
    ): array {
        $matched = [];
        $controllerClass = ltrim($declaration->controllerClass ?? '', '\\');
        $expectedNames = $declaration->generatedRouteNames;

        if ($expectedNames !== []) {
            foreach ($expectedNames as $name) {
                $route = $discoveredRoutes[$name] ?? null;

                if (
                    !$route instanceof RangerRoute
                    || !$route->hasController()
                    || ltrim($route->controller(), '\\') !== $controllerClass
                ) {
                    continue;
                }

                $matched[$name] = $route;
            }

            ksort($matched);

            return $matched;
        }

        $prefix = $declaration->resourceName . '.';

        foreach ($discoveredRoutes as $name => $route) {
            if (
                !$route instanceof RangerRoute
                || !is_string($name)
                || !str_starts_with($name, $prefix)
                || !$route->hasController()
                || ltrim($route->controller(), '\\') !== $controllerClass
            ) {
                continue;
            }

            $matched[$name] = $route;
        }

        ksort($matched);

        return $matched;
    }

    /**
     * @return array<string, string>
     */
    private function viewSymbolsByName(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        array $declarations,
    ): array {
        $finder = app('view')->getFinder();

        if (!is_object($finder) || !method_exists($finder, 'find')) {
            return [];
        }

        $symbols = [];

        foreach ($declarations as $declaration) {
            $viewName = $this->declarationViewName($declaration);

            if ($viewName === null || isset($symbols[$viewName])) {
                continue;
            }

            try {
                $resolvedPath = $finder->find($viewName);
            } catch (Throwable) {
                continue;
            }

            if (
                !is_string($resolvedPath)
                || !str_ends_with($resolvedPath, '.blade.php')
                || !$this->isProjectBladePath($context, $resolvedPath)
            ) {
                continue;
            }

            $symbols[$viewName] = $normalizer->view($viewName);
        }

        ksort($symbols);

        return $symbols;
    }

    private function declarationViewName(\Bnomei\ScipLaravel\Support\PhpRouteDeclaration $declaration): ?string
    {
        if (is_string($declaration->viewName) && $declaration->viewName !== '') {
            return $declaration->viewName;
        }

        if (!is_string($declaration->componentName) || $declaration->componentName === '') {
            return null;
        }

        return $this->normalizedLivewireViewName($declaration->componentName);
    }

    private function normalizedLivewireViewName(string $literal): ?string
    {
        $literal = ltrim($literal, '\\');

        if ($literal === '') {
            return null;
        }

        if (str_contains($literal, '::')) {
            return str_replace('::', '.', $literal);
        }

        $literal = str_replace(['::', '/', '\\'], ['.', '.', '.'], $literal);

        return str_starts_with($literal, 'livewire.') ? $literal : 'livewire.' . $literal;
    }

    private function isProjectBladePath(LaravelContext $context, string $path): bool
    {
        $resolved = realpath($path);
        $projectRoot = realpath($context->projectRoot);

        if (!is_string($resolved) || !is_string($projectRoot) || !str_ends_with($resolved, '.blade.php')) {
            return false;
        }

        return str_starts_with($resolved, $projectRoot . DIRECTORY_SEPARATOR) || $resolved === $projectRoot;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }

    /**
     * @param array<string, ?ClassResult> $analysisCache
     * @return list<string>
     */
    private function controllerMethodDocumentation(
        LaravelContext $context,
        object $route,
        string $controllerClass,
        string $controllerMethod,
        array &$analysisCache,
        array $routeParameterContracts = [],
    ): array {
        if (!$route instanceof RangerRoute) {
            return [];
        }

        $documentation = [];
        $name = $route->name();
        $verbs = $this->routeVerbs($route);

        if (is_string($name) && $name !== '' && $verbs !== '') {
            $documentation[] = 'Laravel route: ' . $name . ' [' . $verbs . '] ' . $route->uri();
        }

        $parameters = $routeParameterContracts !== []
            ? $this->routeParameterDocumentationFromContracts($routeParameterContracts)
            : $this->routeParameterDocumentation($route);

        if ($parameters !== null) {
            $documentation[] = $parameters;
        }

        $validator = $this->routeValidatorDocumentation($route->requestValidator());

        if ($validator !== null) {
            $documentation[] = $validator;
        }

        $responses = $this->routeResponseDocumentation($route);

        if ($responses !== null) {
            $documentation[] = $responses;
        }

        foreach ($this->responseClassDocumentation($context, $route, $analysisCache) as $line) {
            $documentation[] = $line;
        }

        $classResult = $this->analyzedController($context, $controllerClass, $analysisCache);

        if ($classResult !== null && $classResult->hasMethod($controllerMethod)) {
            $rules = $classResult->getMethod($controllerMethod)->validationRules();

            if ($rules !== []) {
                $formatted = $this->validationRuleFormatter->formatSurveyorRuleMap($rules);

                if ($formatted !== '') {
                    $documentation[] = 'Laravel validation rules: ' . $formatted;
                }
            }
        }

        return $this->normalizedDocumentation($documentation);
    }

    /**
     * @param array<string, ?ClassResult> $analysisCache
     */
    private function analyzedController(
        LaravelContext $context,
        string $controllerClass,
        array &$analysisCache,
    ): ?ClassResult {
        if (array_key_exists($controllerClass, $analysisCache)) {
            return $analysisCache[$controllerClass];
        }

        $analysisCache[$controllerClass] = $context->surveyor->class($controllerClass);

        return $analysisCache[$controllerClass];
    }

    private function routeVerbs(RangerRoute $route): string
    {
        $verbs = [];

        foreach ($route->verbs() as $verb) {
            if (is_object($verb) && isset($verb->verb) && is_string($verb->verb)) {
                $verbs[] = strtoupper($verb->verb);
            }
        }

        $verbs = $this->normalizedDocumentation($verbs);

        return implode(', ', $verbs);
    }

    private function routeParameterDocumentation(RangerRoute $route): ?string
    {
        $parts = [];

        foreach ($route->parameters() as $parameter) {
            if (!$parameter instanceof RouteParameter) {
                continue;
            }

            $types = [];

            $boundClass = $parameter->bound === null
                ? $this->reflectedRouteParameterClass($route, $parameter)
                : Reflector::getParameterClassName($parameter->bound);

            if (is_string($boundClass) && enum_exists($boundClass)) {
                $types[] = ltrim($boundClass, '\\');
            } else {
                foreach ($parameter->types as $type) {
                    $types[] = $this->typeFormatter->format($type);
                }
            }

            $types = $this->normalizedDocumentation($types);
            $label = $parameter->name . ($parameter->optional ? '?' : '');

            if ($types !== []) {
                $label .= ': ' . implode('|', $types);
            }

            if ($parameter->placeholder !== '') {
                $label .= ' [' . $parameter->placeholder . ']';
            }

            if (is_string($parameter->key) && $parameter->key !== '') {
                $label .= ' (binding key: ' . $parameter->key . ')';
            }

            if (is_string($parameter->default) && $parameter->default !== '') {
                $label .= ' (default: ' . $parameter->default . ')';
            }

            $parts[] = $label;
        }

        if ($parts === []) {
            return null;
        }

        return 'Parameters: ' . implode(', ', $parts);
    }

    /**
     * @param list<\Bnomei\ScipLaravel\Support\RouteParameterContract> $contracts
     */
    private function routeParameterDocumentationFromContracts(array $contracts): ?string
    {
        if ($contracts === []) {
            return null;
        }

        $parts = [];

        foreach ($contracts as $contract) {
            $label = $contract->parameterName . ($contract->optional ? '?' : '');

            if ($contract->types !== []) {
                $label .= ': ' . implode('|', $contract->types);
            }

            if (is_string($contract->placeholder) && $contract->placeholder !== '') {
                $label .= ' [' . $contract->placeholder . ']';
            }

            if (is_string($contract->bindingKey) && $contract->bindingKey !== '') {
                $label .= ' (binding key: ' . $contract->bindingKey . ')';
            }

            if (is_string($contract->defaultValue) && $contract->defaultValue !== '') {
                $label .= ' (default: ' . $contract->defaultValue . ')';
            }

            $parts[] = $label;
        }

        if ($parts === []) {
            return null;
        }

        return 'Parameters: ' . implode(', ', $parts);
    }

    /**
     * @return list<DocumentSymbolPatch|DocumentOccurrencePatch>
     */
    private function routeRuntimeContractPatches(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $routeName,
        RangerRoute $route,
        string $routeSymbol,
        \Bnomei\ScipLaravel\Support\PhpRouteDeclaration $definition,
        array &$analysisCache,
    ): array {
        $patches = [];
        $relativePath = $context->relativeProjectPath($definition->filePath);
        $anchorRange = $definition->targetRange ?? $definition->anchorRange ?? $definition->nameRange;
        $definitionRange = $definition->nameRange ?? $definition->anchorRange ?? $definition->targetRange;

        $responseDocumentation = $this->routeResponseContractDocumentation($context, $route, $analysisCache);

        if ($responseDocumentation !== []) {
            $responseSymbol = $normalizer->routeResponse($routeName);
            $patches[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                'symbol' => $responseSymbol,
                'display_name' => 'response',
                'kind' => Kind::Key,
                'documentation' => $responseDocumentation,
                'enclosing_symbol' => $routeSymbol,
            ]));

            if ($anchorRange instanceof SourceRange) {
                $patches[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                    'range' => $anchorRange->toScipRange(),
                    'symbol' => $responseSymbol,
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'enclosing_range' => $definitionRange?->toScipRange() ?? [],
                    'override_documentation' => ['Route response contract: ' . $routeName],
                ]));
            }
        }

        $validatorDocumentation = $this->routeValidatorContractDocumentation($routeName, $route);

        if ($validatorDocumentation !== []) {
            $validatorSymbol = $normalizer->routeValidator($routeName);
            $patches[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                'symbol' => $validatorSymbol,
                'display_name' => 'validator',
                'kind' => Kind::Key,
                'documentation' => $validatorDocumentation,
                'enclosing_symbol' => $routeSymbol,
            ]));

            if ($anchorRange instanceof SourceRange) {
                $patches[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                    'range' => $anchorRange->toScipRange(),
                    'symbol' => $validatorSymbol,
                    'symbol_roles' => SymbolRole::Definition,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                    'enclosing_range' => $definitionRange?->toScipRange() ?? [],
                    'override_documentation' => ['Route validator contract: ' . $routeName],
                ]));
            }
        }

        return $patches;
    }

    /**
     * @return list<string>
     */
    private function routeResponseContractDocumentation(
        LaravelContext $context,
        RangerRoute $route,
        array &$analysisCache,
    ): array {
        $documentation = [];
        $summary = $this->routeResponseDocumentation($route);
        $responseClassDocumentation = [];

        if (is_string($summary) && $summary !== '') {
            $documentation[] = $summary;
        }

        $responseClassDocumentation = $this->responseClassDocumentation($context, $route, $analysisCache);

        foreach ($responseClassDocumentation as $line) {
            $documentation[] = $line;
        }

        if ($documentation !== [] && (!is_string($summary) || $summary === '')) {
            array_unshift($documentation, 'Route response contract');
        } elseif ($documentation !== []) {
            array_unshift($documentation, 'Route response contract');
        }

        return $this->normalizedDocumentation($documentation);
    }

    /**
     * @return list<string>
     */
    private function routeValidatorContractDocumentation(string $routeName, RangerRoute $route): array
    {
        $documentation = [];
        $summary = $this->routeValidatorDocumentation($route->requestValidator());

        if (is_string($summary) && $summary !== '') {
            $documentation[] = 'Route validator contract';
            $documentation[] = $summary;
        }

        if ($documentation !== []) {
            $documentation[] = 'Route: ' . $routeName;
        }

        return $this->normalizedDocumentation($documentation);
    }

    private function reflectedRouteParameterClass(RangerRoute $route, RouteParameter $parameter): ?string
    {
        if (!$route->hasController()) {
            return null;
        }

        $reflection = $this->reflectionForClass($route->controller());

        if (!$reflection instanceof ReflectionClass) {
            return null;
        }

        if (!$reflection->hasMethod($route->method())) {
            return null;
        }

        foreach ($reflection->getMethod($route->method())->getParameters() as $methodParameter) {
            if ($methodParameter->getName() !== $parameter->name) {
                continue;
            }

            return Reflector::getParameterClassName($methodParameter);
        }

        return null;
    }

    private function reflectionForClass(string $className): ?ReflectionClass
    {
        if (array_key_exists($className, $this->controllerReflectionCache)) {
            return $this->controllerReflectionCache[$className];
        }

        try {
            return $this->controllerReflectionCache[$className] = new ReflectionClass($className);
        } catch (ReflectionException) {
            return $this->controllerReflectionCache[$className] = null;
        }
    }

    private function routeValidatorDocumentation(?RangerValidator $validator): ?string
    {
        if (!$validator instanceof RangerValidator || $validator->rules === []) {
            return null;
        }

        $cacheKey = (string) spl_object_id($validator);

        if (array_key_exists($cacheKey, $this->routeValidatorDocumentationCache)) {
            return $this->routeValidatorDocumentationCache[$cacheKey];
        }

        $formatted = $this->validationRuleFormatter->formatRangerRuleMap($validator->rules);

        if ($formatted !== '') {
            return $this->routeValidatorDocumentationCache[$cacheKey] = 'Validator rules: ' . $formatted;
        }

        $keys = array_keys($validator->rules);
        sort($keys);

        return $this->routeValidatorDocumentationCache[$cacheKey] = $keys === []
            ? null
            : 'Validator keys: ' . implode(', ', $keys);
    }

    private function routeResponseDocumentation(RangerRoute $route): ?string
    {
        $name = $route->name();

        if (is_string($name) && $name !== '' && array_key_exists($name, $this->routeResponseDocumentationCache)) {
            return $this->routeResponseDocumentationCache[$name];
        }

        $responses = [];
        $jsonShapes = [];

        foreach ($route->possibleResponses() as $response) {
            if ($response instanceof InertiaResponse) {
                $responses[] = 'Inertia ' . $response->component;
                continue;
            }

            if ($response instanceof JsonResponse) {
                $jsonShapes[] = new \Laravel\Surveyor\Types\ArrayType($response->data);
                continue;
            }

            if (is_string($response) && trim($response) !== '') {
                $responses[] = $response;
                continue;
            }

            if (is_object($response)) {
                $responses[] = class_basename(get_class($response));
            }
        }

        $jsonContract = $this->contractFormatter->formatArrayShapes($jsonShapes, 'JSON contract');

        if ($jsonContract !== null) {
            $responses[] = $jsonContract;
        }

        $responses = $this->normalizedDocumentation($responses);

        if ($responses === []) {
            return null;
        }

        $documentation = 'Responses: ' . implode('; ', $responses);

        if (is_string($name) && $name !== '') {
            $this->routeResponseDocumentationCache[$name] = $documentation;
        }

        return $documentation;
    }

    /**
     * @param array<string, ?ClassResult> $analysisCache
     * @return list<string>
     */
    private function responseClassDocumentation(
        LaravelContext $context,
        RangerRoute $route,
        array &$analysisCache,
    ): array {
        if (!$route->hasController()) {
            return [];
        }

        $classResult = $this->analyzedController($context, ltrim($route->controller(), '\\'), $analysisCache);

        if ($classResult === null || !$classResult->hasMethod($route->method())) {
            return [];
        }

        $documentation = [];

        foreach ($classResult->getMethod($route->method())->returnTypes() as $variant) {
            $type = $variant['type'] ?? null;

            if (!$type instanceof \Laravel\Surveyor\Types\ClassType) {
                continue;
            }

            $className = ltrim($type->resolved(), '\\');
            $result = $context->surveyor->class($className);

            if ($result === null) {
                continue;
            }

            if (is_subclass_of($className, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                $documentation[] = 'API resource: ' . $className;
            } elseif ($result->isArrayable() || $result->isJsonSerializable()) {
                $documentation[] = 'Serializable response: ' . $className;
            } else {
                continue;
            }

            $shape = $result->asArray()?->returnType() ?? $result->asJson()?->returnType();

            if ($shape instanceof SurveyorType) {
                $formatted = $this->contractFormatter->formatTypeContract($shape, 'Response shape');

                if ($formatted !== null) {
                    $documentation[] = $formatted;
                }
            }
        }

        return $this->normalizedDocumentation($documentation);
    }

    /**
     * @param array<string, ?ClassResult> $analysisCache
     * @return list<string>
     */
    private function responseReferenceSymbols(LaravelContext $context, RangerRoute $route, array &$analysisCache): array
    {
        if (!$route->hasController()) {
            return [];
        }

        $classResult = $this->analyzedController($context, ltrim($route->controller(), '\\'), $analysisCache);

        if ($classResult === null || !$classResult->hasMethod($route->method())) {
            return [];
        }

        $symbols = [];

        foreach ($classResult->getMethod($route->method())->returnTypes() as $variant) {
            $type = $variant['type'] ?? null;

            if (!$type instanceof \Laravel\Surveyor\Types\ClassType) {
                continue;
            }

            $className = ltrim($type->resolved(), '\\');
            $result = $context->surveyor->class($className);

            if ($result === null) {
                continue;
            }

            $reflection = $this->reflectionForClass($className);

            if (!$reflection instanceof ReflectionClass) {
                continue;
            }

            $filePath = $reflection->getFileName();

            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);

            if (!str_starts_with($documentPath, 'app/')) {
                continue;
            }

            $classSymbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $className,
                $reflection->getStartLine(),
            );

            if (is_string($classSymbol) && $classSymbol !== '') {
                $symbols[$classSymbol] = $classSymbol;
            }

            foreach ($this->responseSerializationMethodSymbols(
                $context,
                $reflection,
                $className,
                $result,
            ) as $methodSymbol) {
                $symbols[$methodSymbol] = $methodSymbol;
            }
        }

        ksort($symbols);

        return array_values($symbols);
    }

    /**
     * @return list<string>
     */
    private function responseSerializationMethodSymbols(
        LaravelContext $context,
        ReflectionClass $reflection,
        string $className,
        ClassResult $result,
    ): array {
        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return [];
        }

        $documentPath = $context->relativeProjectPath($filePath);
        $symbols = [];

        foreach (['toArray', 'jsonSerialize'] as $methodName) {
            if (
                !$reflection->hasMethod($methodName)
                || $methodName === 'toArray'
                && !(
                    $result->isArrayable()
                    || is_subclass_of($className, \Illuminate\Http\Resources\Json\JsonResource::class)
                )
                || $methodName === 'jsonSerialize' && !$result->isJsonSerializable()
            ) {
                continue;
            }

            $methodSymbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                $methodName,
                $reflection->getMethod($methodName)->getStartLine(),
            );

            if (is_string($methodSymbol) && $methodSymbol !== '') {
                $symbols[] = $methodSymbol;
            }
        }

        sort($symbols);

        return array_values(array_unique($symbols));
    }

    /**
     * @param list<string> $documentation
     * @return list<string>
     */
    private function normalizedDocumentation(array $documentation): array
    {
        $normalized = [];

        foreach ($documentation as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $normalized[] = trim($line);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}

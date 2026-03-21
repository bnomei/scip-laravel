<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Reflector;
use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Ranger\Support\RouteParameter;

use function array_key_exists;
use function array_values;
use function class_exists;
use function enum_exists;
use function is_string;
use function ksort;
use function ltrim;
use function sort;
use function spl_object_id;

final class RouteParameterContractInventoryBuilder
{
    /**
     * @var array<string, array{
     *     byRouteName: array<string, array<string, RouteParameterContract>>,
     *     byFormRequestClass: array<string, array<string, RouteParameterContract>>,
     *     byControllerMethod: array<string, array<string, RouteParameterContract>>
     * }>
     */
    private static array $inventoryCache = [];

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
        private readonly PhpRouteDeclarationFinder $routeDeclarationFinder = new PhpRouteDeclarationFinder(),
    ) {}

    public static function reset(): void
    {
        self::$inventoryCache = [];
    }

    /**
     * @return array{
     *     byRouteName: array<string, array<string, RouteParameterContract>>,
     *     byFormRequestClass: array<string, array<string, RouteParameterContract>>,
     *     byControllerMethod: array<string, array<string, RouteParameterContract>>
     * }
     */
    public function build(LaravelContext $context): array
    {
        $cacheKey =
            $context->projectRoot
            . "\x1F"
            . spl_object_id($context->rangerSnapshot)
            . "\x1F"
            . spl_object_id($context->application);

        if (isset(self::$inventoryCache[$cacheKey])) {
            return self::$inventoryCache[$cacheKey];
        }

        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $parameterDefaultsByRoute = $this->parameterDefaultsByRoute($context);
        $byRouteName = [];
        $byFormRequestCandidates = [];
        $byControllerMethodCandidates = [];

        foreach ($context->rangerSnapshot->routes as $route) {
            if (!$route instanceof RangerRoute) {
                continue;
            }

            $routeName = $route->name();

            if (!is_string($routeName) || $routeName === '') {
                continue;
            }

            $contracts = [];

            foreach ($route->parameters() as $parameter) {
                if (!$parameter instanceof RouteParameter || $parameter->name === '') {
                    continue;
                }

                $types = [];
                $boundClass = $parameter->bound === null
                    ? $this->reflectedControllerParameterClass($route, $parameter)
                    : Reflector::getParameterClassName($parameter->bound);

                if (
                    is_string($boundClass)
                    && ($context->surveyor->class(ltrim($boundClass, '\\')) !== null || enum_exists($boundClass))
                ) {
                    $types[] = ltrim($boundClass, '\\');
                } else {
                    foreach ($parameter->types as $type) {
                        $types[] = $this->typeFormatter->format($type);
                    }
                }

                $types = array_values(array_unique(array_filter(
                    $types,
                    static fn(string $value): bool => $value !== '',
                )));
                sort($types);
                $documentation = ['Route parameter: ' . $parameter->name];
                $defaultValue = $this->resolvedDefaultValue(
                    routeName: $routeName,
                    parameterName: $parameter->name,
                    rangerDefault: $parameter->default,
                    parameterDefaultsByRoute: $parameterDefaultsByRoute,
                );

                if ($parameter->placeholder !== '') {
                    $documentation[] = 'Route placeholder: ' . $parameter->placeholder;
                }

                $documentation[] = $parameter->optional ? 'Route optional: true' : 'Route optional: false';

                if ($defaultValue !== null && $defaultValue !== '') {
                    $documentation[] = 'Route default: ' . $defaultValue;
                }

                if (is_string($parameter->key) && $parameter->key !== '') {
                    $documentation[] = 'Route binding key: ' . $parameter->key;
                }

                if ($types !== []) {
                    $documentation[] = 'Route parameter types: ' . implode('|', $types);
                }

                $contracts[$parameter->name] = new RouteParameterContract(
                    routeName: $routeName,
                    parameterName: $parameter->name,
                    symbol: $normalizer->routeParameter($routeName, $parameter->name),
                    optional: $parameter->optional,
                    placeholder: $parameter->placeholder,
                    defaultValue: $defaultValue,
                    bindingKey: $parameter->key,
                    boundClass: is_string($boundClass) && $boundClass !== '' ? ltrim($boundClass, '\\') : null,
                    types: $types,
                    documentation: $documentation,
                );
            }

            if ($contracts === []) {
                continue;
            }

            ksort($contracts);
            $byRouteName[$routeName] = $contracts;

            if (!$route->hasController() || !class_exists($route->controller())) {
                continue;
            }

            $reflection = new \ReflectionClass($route->controller());

            if (!$reflection->hasMethod($route->method())) {
                continue;
            }

            $controllerKey = $this->controllerMethodKey($route->controller(), $route->method());

            foreach ($contracts as $contract) {
                $existing = $byControllerMethodCandidates[$controllerKey][$contract->parameterName] ?? null;

                if ($existing instanceof RouteParameterContract && $existing->symbol !== $contract->symbol) {
                    $byControllerMethodCandidates[$controllerKey][$contract->parameterName] = null;
                    continue;
                }

                if (!array_key_exists($contract->parameterName, $byControllerMethodCandidates[$controllerKey] ?? [])) {
                    $byControllerMethodCandidates[$controllerKey][$contract->parameterName] = $contract;
                }
            }

            foreach ($reflection->getMethod($route->method())->getParameters() as $methodParameter) {
                $formRequestClass = Reflector::getParameterClassName($methodParameter);

                if (
                    !is_string($formRequestClass)
                    || $formRequestClass === ''
                    || !is_subclass_of($formRequestClass, FormRequest::class)
                ) {
                    continue;
                }

                foreach ($contracts as $contract) {
                    $candidateKey = $formRequestClass . "\n" . $contract->parameterName;
                    $existing = $byFormRequestCandidates[$candidateKey] ?? null;

                    if ($existing instanceof RouteParameterContract && $existing->symbol !== $contract->symbol) {
                        $byFormRequestCandidates[$candidateKey] = null;
                        continue;
                    }

                    if (!array_key_exists($candidateKey, $byFormRequestCandidates)) {
                        $byFormRequestCandidates[$candidateKey] = $contract;
                    }
                }
            }
        }

        ksort($byRouteName);
        $byFormRequestClass = [];

        foreach ($byFormRequestCandidates as $candidateKey => $contract) {
            if (!$contract instanceof RouteParameterContract) {
                continue;
            }

            [$className, $parameterName] = explode("\n", $candidateKey, 2);
            $byFormRequestClass[$className][$parameterName] = $contract;
        }

        foreach ($byFormRequestClass as $className => $contracts) {
            ksort($contracts);
            $byFormRequestClass[$className] = $contracts;
        }

        ksort($byFormRequestClass);
        $byControllerMethod = [];

        foreach ($byControllerMethodCandidates as $controllerKey => $contracts) {
            foreach ($contracts as $parameterName => $contract) {
                if (!$contract instanceof RouteParameterContract) {
                    continue;
                }

                $byControllerMethod[$controllerKey][$parameterName] = $contract;
            }

            if (($byControllerMethod[$controllerKey] ?? []) === []) {
                unset($byControllerMethod[$controllerKey]);
                continue;
            }

            ksort($byControllerMethod[$controllerKey]);
        }

        ksort($byControllerMethod);

        return self::$inventoryCache[$cacheKey] = [
            'byRouteName' => $byRouteName,
            'byFormRequestClass' => $byFormRequestClass,
            'byControllerMethod' => $byControllerMethod,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function parameterDefaultsByRoute(LaravelContext $context): array
    {
        $defaultsByRoute = [];

        foreach ($this->routeDeclarationFinder->find($context->projectRoot) as $declaration) {
            if ($declaration->nameLiteral === null || $declaration->parameterDefaults === []) {
                continue;
            }

            foreach ($declaration->parameterDefaults as $parameterName => $defaultValue) {
                if ($parameterName === '' || $defaultValue === '') {
                    continue;
                }

                $defaultsByRoute[$declaration->nameLiteral][$parameterName] = $defaultValue;
            }
        }

        ksort($defaultsByRoute);

        return $defaultsByRoute;
    }

    /**
     * @param array<string, array<string, string>> $parameterDefaultsByRoute
     */
    private function resolvedDefaultValue(
        string $routeName,
        string $parameterName,
        ?string $rangerDefault,
        array $parameterDefaultsByRoute,
    ): ?string {
        if ($rangerDefault !== null && $rangerDefault !== '') {
            return $rangerDefault;
        }

        return $parameterDefaultsByRoute[$routeName][$parameterName] ?? null;
    }

    private function controllerMethodKey(string $className, string $methodName): string
    {
        return ltrim($className, '\\') . "\n" . $methodName;
    }

    private function reflectedControllerParameterClass(RangerRoute $route, RouteParameter $parameter): ?string
    {
        if (!$route->hasController() || !class_exists($route->controller())) {
            return null;
        }

        $reflection = new \ReflectionClass($route->controller());

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
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

use function array_key_exists;
use function array_keys;
use function is_string;
use function ltrim;

final class ControllerRouteParameterFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    private NodeFinder $nodeFinder;

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @param array<string, array<string, RouteParameterContract>> $contractsByControllerMethod
     * @return list<ControllerRouteParameterReference>
     */
    public function find(array $contractsByControllerMethod): array
    {
        if ($contractsByControllerMethod === []) {
            return [];
        }

        $references = [];

        foreach (array_keys($contractsByControllerMethod) as $controllerKey) {
            [$controllerClass, $controllerMethod] = explode("\n", $controllerKey, 2);

            if (!class_exists($controllerClass)) {
                continue;
            }

            $reflection = new \ReflectionClass($controllerClass);
            $filePath = $reflection->getFileName();

            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            $contents = $this->analysisCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $ast = $this->analysisCache->resolvedAst($filePath);

            if ($ast === null) {
                continue;
            }
            $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);

            if (!$class instanceof Class_) {
                continue;
            }

            $resolvedClass = $class->namespacedName instanceof Name
                ? ltrim($class->namespacedName->toString(), '\\')
                : null;

            if ($resolvedClass === null || $resolvedClass !== ltrim($controllerClass, '\\')) {
                continue;
            }

            $method = $this->classMethod($class, $controllerMethod);

            if (!$method instanceof ClassMethod) {
                continue;
            }

            $contracts = $contractsByControllerMethod[$controllerKey];
            $requestVariables = $this->requestVariables($method);

            if ($requestVariables === []) {
                continue;
            }

            foreach ($this->nodeFinder->findInstanceOf($method->stmts ?? [], MethodCall::class) as $call) {
                if (
                    !$call instanceof MethodCall
                    || !$call->var instanceof Variable
                    || !is_string($call->var->name)
                    || !isset($requestVariables[$call->var->name])
                    || !$call->name instanceof Node\Identifier
                    || $call->name->toString() !== 'route'
                    || !($call->getArgs()[0]->value ?? null) instanceof String_
                ) {
                    continue;
                }

                $parameterName = $call->getArgs()[0]->value->value;
                $contract = $contracts[$parameterName] ?? null;

                if (!$contract instanceof RouteParameterContract) {
                    continue;
                }

                $references[] = new ControllerRouteParameterReference(
                    filePath: $filePath,
                    controllerClass: $resolvedClass,
                    controllerMethod: $controllerMethod,
                    parameterName: $parameterName,
                    range: SourceRange::fromOffsets(
                        $contents,
                        $call->getArgs()[0]->value->getStartFilePos(),
                        $call->getArgs()[0]->value->getEndFilePos() + 1,
                    ),
                );
            }
        }

        return $references;
    }

    private function classMethod(Class_ $class, string $methodName): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private function requestVariables(ClassMethod $method): array
    {
        $variables = [];

        foreach ($method->params as $parameter) {
            if (!$parameter->type instanceof Name) {
                continue;
            }

            $resolved = $parameter->type->getAttribute('resolvedName');
            $className = $resolved instanceof Name
                ? ltrim($resolved->toString(), '\\')
                : ltrim($parameter->type->toString(), '\\');

            if (
                !is_subclass_of($className, Request::class)
                && !is_subclass_of($className, FormRequest::class)
                && $className !== Request::class
                && $className !== FormRequest::class
            ) {
                continue;
            }

            if (is_string($parameter->var->name) && $parameter->var->name !== '') {
                $variables[$parameter->var->name] = true;
            }
        }

        return $variables;
    }
}

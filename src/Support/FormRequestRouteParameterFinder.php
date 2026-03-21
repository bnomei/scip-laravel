<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Foundation\Http\FormRequest;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;

use function array_key_exists;
use function is_string;
use function ltrim;

final class FormRequestRouteParameterFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    private NodeFinder $nodeFinder;

    /**
     * @var array<string, ?string>
     */
    private array $classFileCache = [];

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @param array<string, array<string, RouteParameterContract>> $contractsByFormRequestClass
     * @return list<FormRequestRouteParameterReference>
     */
    public function find(array $contractsByFormRequestClass): array
    {
        if ($contractsByFormRequestClass === []) {
            return [];
        }

        $references = [];

        foreach (array_keys($contractsByFormRequestClass) as $className) {
            $filePath = $this->classFilePath($className);

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

            if (
                $resolvedClass === null
                || !array_key_exists($resolvedClass, $contractsByFormRequestClass)
                || !is_subclass_of($resolvedClass, FormRequest::class)
            ) {
                continue;
            }

            $contracts = $contractsByFormRequestClass[$resolvedClass];

            foreach ($this->nodeFinder->findInstanceOf($class->stmts ?? [], MethodCall::class) as $call) {
                if (
                    !$call instanceof MethodCall
                    || !$call->var instanceof Variable
                    || $call->var->name !== 'this'
                    || !$call->name instanceof Node\Identifier
                    || $call->name->toString() !== 'route'
                    || ($call->getArgs()[0]->value ?? null) === null
                    || !$call->getArgs()[0]->value instanceof String_
                ) {
                    continue;
                }

                $parameterName = $call->getArgs()[0]->value->value;
                $contract = $contracts[$parameterName] ?? null;

                if (!$contract instanceof RouteParameterContract) {
                    continue;
                }

                $range = SourceRange::fromOffsets(
                    $contents,
                    $call->getArgs()[0]->value->getStartFilePos(),
                    $call->getArgs()[0]->value->getEndFilePos() + 1,
                );

                $references[] = new FormRequestRouteParameterReference(
                    filePath: $filePath,
                    className: $resolvedClass,
                    parameterName: $parameterName,
                    range: $range,
                    propertyShortcut: false,
                );
            }

            foreach ($this->nodeFinder->findInstanceOf($class->stmts ?? [], PropertyFetch::class) as $fetch) {
                if (
                    !$fetch instanceof PropertyFetch
                    || !$fetch->var instanceof Variable
                    || $fetch->var->name !== 'this'
                    || !$fetch->name instanceof Node\Identifier
                ) {
                    continue;
                }

                $parameterName = $fetch->name->toString();
                $contract = $contracts[$parameterName] ?? null;

                if (!$contract instanceof RouteParameterContract || $contract->boundClass === null) {
                    continue;
                }

                $range = SourceRange::fromOffsets(
                    $contents,
                    $fetch->name->getStartFilePos(),
                    $fetch->name->getEndFilePos() + 1,
                );

                $references[] = new FormRequestRouteParameterReference(
                    filePath: $filePath,
                    className: $resolvedClass,
                    parameterName: $parameterName,
                    range: $range,
                    propertyShortcut: true,
                );
            }
        }

        return $references;
    }

    private function classFilePath(string $className): ?string
    {
        if (array_key_exists($className, $this->classFileCache)) {
            return $this->classFileCache[$className];
        }

        if (!class_exists($className)) {
            return $this->classFileCache[$className] = null;
        }

        $filePath = (new \ReflectionClass($className))->getFileName();

        return $this->classFileCache[$className] = is_string($filePath) && $filePath !== '' ? $filePath : null;
    }
}

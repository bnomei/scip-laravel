<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Support\ServiceProvider;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

use function array_merge;
use function is_array;
use function is_dir;
use function is_string;
use function ltrim;
use function sort;
use function str_contains;
use function strtolower;

final class ContainerBindingExtractor
{
    private NodeFinder $nodeFinder;

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    /**
     * @return list<ContainerBindingFact>
     */
    public function extract(string $projectRoot): array
    {
        return array_merge($this->providerFacts($projectRoot), $this->attributeFacts($projectRoot));
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function providerFacts(string $projectRoot): array
    {
        $root = $projectRoot . '/app/Providers';

        if (!is_dir($root)) {
            return [];
        }

        $facts = [];

        foreach ($this->analysisCache->phpFilesInRoots([$root]) as $filePath) {
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

            $className = $this->resolvedClassName($class);

            if ($className === null || !is_subclass_of($className, ServiceProvider::class)) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                $facts = array_merge($facts, $this->propertyFacts($property, $filePath, $contents));
            }

            foreach ($class->getMethods() as $method) {
                $facts = array_merge($facts, $this->methodFacts($method, $filePath, $contents));
            }
        }

        return $facts;
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function attributeFacts(string $projectRoot): array
    {
        $root = $projectRoot . '/app';

        if (!is_dir($root)) {
            return [];
        }

        $facts = [];

        foreach ($this->analysisCache->phpFilesInRoots([$root]) as $filePath) {
            if (str_contains($filePath, DIRECTORY_SEPARATOR . 'Providers' . DIRECTORY_SEPARATOR)) {
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

            foreach ($this->nodeFinder->find(
                $ast,
                static fn(Node $node): bool => $node instanceof Class_ || $node instanceof Interface_,
            ) as $classLike) {
                if (!$classLike instanceof ClassLike) {
                    continue;
                }

                $facts = array_merge(
                    $facts,
                    $this->classLikeAttributeFacts($classLike, $filePath, $contents),
                    $this->parameterAttributeFacts($classLike, $filePath, $contents),
                );
            }
        }

        return $facts;
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function propertyFacts(Property $property, string $filePath, string $contents): array
    {
        $propertyName = $property->props[0]->name->toString() ?? null;

        if ($propertyName !== 'bindings' && $propertyName !== 'singletons') {
            return [];
        }

        $default = $property->props[0]->default ?? null;

        if (!$default instanceof Array_) {
            return [];
        }

        $facts = [];

        foreach ($default->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            $contract = $this->classLiteral($item->key, $contents);
            $implementation = $this->classLiteral($item->value, $contents);

            if ($contract === null || $implementation === null) {
                continue;
            }

            $facts[] = new ContainerBindingFact(
                filePath: $filePath,
                kind: 'binding',
                bindingType: $propertyName === 'singletons' ? 'singleton' : 'bind',
                contractClass: $contract['class'],
                contractRange: $contract['range'],
                implementationClass: $implementation['class'],
                implementationRange: $implementation['range'],
            );
        }

        return $facts;
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function methodFacts(ClassMethod $method, string $filePath, string $contents): array
    {
        $facts = [];

        foreach ((array) $method->stmts as $statement) {
            if (!$statement instanceof Expression) {
                continue;
            }

            $expr = $statement->expr;

            if ($expr instanceof MethodCall) {
                $fact = $this->bindingFact($expr, $filePath, $contents);

                if ($fact !== null) {
                    $facts[] = $fact;
                }

                $contextualFact = $this->contextualBindingFact($expr, $filePath, $contents);

                if ($contextualFact !== null) {
                    $facts[] = $contextualFact;
                }
            }
        }

        return $facts;
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function classLikeAttributeFacts(ClassLike $classLike, string $filePath, string $contents): array
    {
        $className = $this->resolvedClassLikeName($classLike);

        if ($className === null) {
            return [];
        }

        $facts = [];
        $classRange = $this->classLikeNameRange($classLike, $contents);

        foreach ($classLike->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->resolvedAttributeName($attribute);

                if ($attributeName === 'Illuminate\\Container\\Attributes\\Bind') {
                    $implementation = $this->classLiteral($attribute->args[0]->value ?? null, $contents);

                    if ($implementation === null) {
                        continue;
                    }

                    $facts[] = new ContainerBindingFact(
                        filePath: $filePath,
                        kind: 'attribute',
                        bindingType: 'bind',
                        contractClass: $className,
                        contractRange: $classRange,
                        implementationClass: $implementation['class'],
                        implementationRange: $implementation['range'],
                        sourceClassName: $className,
                        sourceClassLine: $classLike->getStartLine(),
                        environments: $this->attributeEnvironmentNames($attribute),
                    );

                    continue;
                }

                if (
                    $attributeName !== 'Illuminate\\Container\\Attributes\\Singleton'
                    && $attributeName !== 'Illuminate\\Container\\Attributes\\Scoped'
                ) {
                    continue;
                }

                $facts[] = new ContainerBindingFact(
                    filePath: $filePath,
                    kind: 'attribute',
                    bindingType: strtolower($attribute->name->getLast()),
                    contractClass: $className,
                    contractRange: $classRange,
                    implementationClass: null,
                    implementationRange: null,
                    sourceClassName: $className,
                    sourceClassLine: $classLike->getStartLine(),
                );
            }
        }

        return $facts;
    }

    /**
     * @return list<ContainerBindingFact>
     */
    private function parameterAttributeFacts(ClassLike $classLike, string $filePath, string $contents): array
    {
        $className = $this->resolvedClassLikeName($classLike);

        if ($className === null) {
            return [];
        }

        $facts = [];
        $classRange = $this->classLikeNameRange($classLike, $contents);

        foreach ($classLike->getMethods() as $method) {
            foreach ($method->getParams() as $parameter) {
                foreach ($parameter->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $domain = $this->contextAttributeDomain($attribute);

                        if ($domain === null) {
                            continue;
                        }

                        $value = $this->attributeStringArgument($attribute);
                        $range = $value === null
                            ? null
                            : $this->nodeRange($attribute->args[0]->value, $contents, trimString: true);

                        if ($value === null || $range === null) {
                            continue;
                        }

                        $facts[] = new ContainerBindingFact(
                            filePath: $filePath,
                            kind: 'contextual-attribute',
                            bindingType: 'contextual-attribute',
                            contractClass: $this->resolvedParameterType($parameter),
                            contractRange: $this->parameterTypeRange($parameter, $contents),
                            implementationClass: null,
                            implementationRange: null,
                            consumerClass: $className,
                            consumerRange: $classRange,
                            sourceClassName: $className,
                            sourceClassLine: $classLike->getStartLine(),
                            contextDomain: $domain,
                            contextValue: $value,
                            contextRange: $range,
                        );
                    }
                }
            }
        }

        return $facts;
    }

    private function bindingFact(MethodCall $call, string $filePath, string $contents): ?ContainerBindingFact
    {
        $methodName = $this->methodName($call);

        if (
            $methodName === null
            || !isset(['bind' => true, 'singleton' => true, 'scoped' => true, 'instance' => true][$methodName])
            || !$this->isAppContainerCall($call->var)
        ) {
            return null;
        }

        $contract = $this->classLiteral($call->getArgs()[0]->value ?? null, $contents);
        $implementation = $this->classLiteral($call->getArgs()[1]->value ?? null, $contents);

        if ($contract === null || $implementation === null) {
            return null;
        }

        return new ContainerBindingFact(
            filePath: $filePath,
            kind: 'binding',
            bindingType: $methodName,
            contractClass: $contract['class'],
            contractRange: $contract['range'],
            implementationClass: $implementation['class'],
            implementationRange: $implementation['range'],
        );
    }

    private function contextualBindingFact(MethodCall $call, string $filePath, string $contents): ?ContainerBindingFact
    {
        if ($this->methodName($call) !== 'give') {
            return null;
        }

        $needsCall = $call->var;

        if (!$needsCall instanceof MethodCall || $this->methodName($needsCall) !== 'needs') {
            return null;
        }

        $whenCall = $needsCall->var;

        if (
            !$whenCall instanceof MethodCall
            || $this->methodName($whenCall) !== 'when'
            || !$this->isAppContainerCall($whenCall->var)
        ) {
            return null;
        }

        $consumer = $this->classLiteral($whenCall->getArgs()[0]->value ?? null, $contents);
        $contract = $this->classLiteral($needsCall->getArgs()[0]->value ?? null, $contents);
        $implementation = $this->classLiteral($call->getArgs()[0]->value ?? null, $contents);

        if ($consumer === null || $contract === null || $implementation === null) {
            return null;
        }

        return new ContainerBindingFact(
            filePath: $filePath,
            kind: 'contextual',
            bindingType: 'contextual',
            contractClass: $contract['class'],
            contractRange: $contract['range'],
            implementationClass: $implementation['class'],
            implementationRange: $implementation['range'],
            consumerClass: $consumer['class'],
            consumerRange: $consumer['range'],
        );
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function classLiteral(mixed $expr, string $contents): ?array
    {
        if (
            $expr instanceof ClassConstFetch
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            $name = $expr->class instanceof Name ? $this->resolvedName($expr->class) : null;

            $range = $this->nodeRange($expr, $contents);

            return $name !== null && $range !== null ? ['class' => $name, 'range' => $range] : null;
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $name = $this->resolvedName($expr->class);
            $range = $this->nodeRange($expr->class, $contents);

            return $name !== null && $range !== null ? ['class' => $name, 'range' => $range] : null;
        }

        if ($expr instanceof String_ && $expr->value !== '') {
            $range = $this->nodeRange($expr, $contents, trimString: true);

            return $range === null ? null : ['class' => ltrim($expr->value, '\\'), 'range' => $range];
        }

        return null;
    }

    private function isAppContainerCall(mixed $expr): bool
    {
        return (
            $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'app'
        );
    }

    private function methodName(MethodCall $call): ?string
    {
        return $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        $name = $class->namespacedName ?? null;

        return $name instanceof Name ? ltrim($name->toString(), '\\') : null;
    }

    private function resolvedClassLikeName(ClassLike $classLike): ?string
    {
        $name = $classLike->namespacedName ?? null;

        return $name instanceof Name ? ltrim($name->toString(), '\\') : null;
    }

    private function resolvedAttributeName(Attribute $attribute): ?string
    {
        $resolvedName = $attribute->name->getAttribute('resolvedName');

        $name = $resolvedName instanceof Name ? $resolvedName->toString() : $attribute->name->toString();

        $name = ltrim($name, '\\');

        return $name !== '' ? $name : null;
    }

    private function contextAttributeDomain(Attribute $attribute): ?string
    {
        return match ($this->resolvedAttributeName($attribute)) {
            'Illuminate\\Container\\Attributes\\Config' => 'config',
            'Illuminate\\Container\\Attributes\\Storage' => 'storage',
            'Illuminate\\Container\\Attributes\\Auth' => 'auth',
            'Illuminate\\Container\\Attributes\\Cache' => 'cache',
            'Illuminate\\Container\\Attributes\\Database' => 'database',
            'Illuminate\\Container\\Attributes\\Log' => 'log',
            'Illuminate\\Container\\Attributes\\RouteParameter' => 'route-parameter',
            'Illuminate\\Container\\Attributes\\Tag' => 'tag',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function attributeEnvironmentNames(Attribute $attribute): array
    {
        foreach ($attribute->args as $argument) {
            if (!$argument->name instanceof Identifier || strtolower($argument->name->toString()) !== 'environments') {
                continue;
            }

            return $this->stringArrayLiteral($argument->value);
        }

        return [];
    }

    private function attributeStringArgument(Attribute $attribute): ?string
    {
        $value = $attribute->args[0]->value ?? null;

        return $value instanceof String_ && $value->value !== '' ? $value->value : null;
    }

    private function resolvedParameterType(Param $parameter): ?string
    {
        $type = $parameter->type;

        return $type instanceof Name ? $this->resolvedName($type) : null;
    }

    private function parameterTypeRange(Param $parameter, string $contents): ?SourceRange
    {
        $type = $parameter->type;

        return $type instanceof Name ? $this->nodeRange($type, $contents) : null;
    }

    /**
     * @return list<string>
     */
    private function stringArrayLiteral(mixed $expr): array
    {
        if (!$expr instanceof Array_) {
            return [];
        }

        $values = [];

        foreach ($expr->items as $item) {
            if ($item === null || !$item->value instanceof String_ || $item->value->value === '') {
                continue;
            }

            $values[] = $item->value->value;
        }

        sort($values);

        return array_values(array_unique($values));
    }

    private function resolvedName(Name $name): ?string
    {
        $resolvedName = $name->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString();

        $className = ltrim($className, '\\');

        return $className !== '' ? $className : null;
    }

    private function classLikeNameRange(ClassLike $classLike, string $contents): ?SourceRange
    {
        $name = $classLike->name;

        if ($name === null) {
            return null;
        }

        return $this->nodeRange($name, $contents);
    }

    private function nodeRange(Node $node, string $contents, bool $trimString = false): ?SourceRange
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        if ($trimString) {
            $start++;
        } else {
            $end++;
        }

        return SourceRange::fromOffsets($contents, $start, $end);
    }
}

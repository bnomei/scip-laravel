<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Livewire\Attributes\On;
use Livewire\Component;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

use function array_merge;
use function is_string;
use function ltrim;
use function strtolower;

final class LivewireEventExtractor
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public function extract(string $filePath): ?LivewireEventExtraction
    {
        return $this->analysisCache->remember('livewire-event-extraction', $filePath, function () use (
            $filePath,
        ): ?LivewireEventExtraction {
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

            if ($className === null || !is_subclass_of($className, Component::class)) {
                return null;
            }

            $references = [];

            foreach ($class->getMethods() as $method) {
                $references = array_merge(
                    $references,
                    $this->listenerReferences($method, $contents),
                    $this->dispatchReferences($method, $contents),
                );
            }

            if ($references === []) {
                return null;
            }

            return new LivewireEventExtraction($className, $references);
        });
    }

    /**
     * @return list<LivewireEventReference>
     */
    private function listenerReferences(ClassMethod $method, string $contents): array
    {
        $references = [];

        foreach ($method->attrGroups as $group) {
            foreach ($this->matchingAttributes($group) as $attribute) {
                $argument = $attribute->args[0]->value ?? null;

                if (!$argument instanceof String_) {
                    continue;
                }

                $range = $this->nodeRange($argument, $contents);

                if ($range === null) {
                    continue;
                }

                $references[] = new LivewireEventReference(
                    eventName: $argument->value,
                    range: $range,
                    methodName: $method->name->toString(),
                    methodLine: $method->getStartLine(),
                    kind: 'listener',
                );
            }
        }

        return $references;
    }

    /**
     * @return list<LivewireEventReference>
     */
    private function dispatchReferences(ClassMethod $method, string $contents): array
    {
        $references = [];

        foreach ($this->nodeFinder->findInstanceOf((array) $method->stmts, MethodCall::class) as $call) {
            if (!$call->var instanceof Variable || $call->var->name !== 'this' || !$call->name instanceof Identifier) {
                continue;
            }

            $methodName = strtolower($call->name->toString());
            $eventArgument = match ($methodName) {
                'dispatch', 'dispatchself' => $call->getArgs()[0] ?? null,
                'dispatchto' => $call->getArgs()[1] ?? null,
                default => null,
            };

            if (!$eventArgument instanceof Arg || !$eventArgument->value instanceof String_) {
                continue;
            }

            $range = $this->nodeRange($eventArgument->value, $contents);

            if ($range === null) {
                continue;
            }

            $references[] = new LivewireEventReference(
                eventName: $eventArgument->value->value,
                range: $range,
                methodName: $method->name->toString(),
                methodLine: $method->getStartLine(),
                kind: 'dispatch',
            );
        }

        return $references;
    }

    /**
     * @return list<Node\Attribute>
     */
    private function matchingAttributes(AttributeGroup $group): array
    {
        $matched = [];

        foreach ($group->attrs as $attribute) {
            $resolvedName = $attribute->name->getAttribute('resolvedName');
            $name = $resolvedName instanceof Name
                ? ltrim($resolvedName->toString(), '\\')
                : ltrim($attribute->name->toString(), '\\');

            if ($name === On::class) {
                $matched[] = $attribute;
            }
        }

        return $matched;
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        $namespacedName = $class->namespacedName ?? null;

        return $namespacedName instanceof Name ? ltrim($namespacedName->toString(), '\\') : null;
    }

    private function nodeRange(Node $node, string $contents): ?SourceRange
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        if ($node instanceof String_) {
            $start++;
            $end = $end;
        }

        return SourceRange::fromOffsets($contents, $start, $end);
    }
}

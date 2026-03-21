<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use ReflectionClass;

use function count;
use function is_string;
use function ksort;
use function ltrim;

final class LiteralModelAttributeFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @return array<string, SourceRange>
     */
    public function find(ReflectionClass $reflection): array
    {
        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return [];
        }

        $contents = $this->analysisCache->contents($filePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($ast === null) {
            return [];
        }
        $targetClass = ltrim($reflection->getName(), '\\');
        $attributes = [];

        foreach ($this->nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof Class_) as $classNode) {
            if (!$classNode instanceof Class_ || $this->className($classNode) !== $targetClass) {
                continue;
            }

            foreach ($classNode->getProperties() as $property) {
                if (count($property->props) !== 1 || $property->props[0]->name->toString() !== 'rows') {
                    continue;
                }

                $default = $property->props[0]->default;

                if (!$default instanceof Array_) {
                    continue;
                }

                foreach ($default->items as $rowItem) {
                    if ($rowItem === null || !$rowItem->value instanceof Array_) {
                        continue;
                    }

                    foreach ($rowItem->value->items as $attributeItem) {
                        if ($attributeItem === null || !$attributeItem->key instanceof String_) {
                            continue;
                        }

                        $attributeName = $attributeItem->key->value;

                        if ($attributeName === '' || isset($attributes[$attributeName])) {
                            continue;
                        }

                        $start = $attributeItem->key->getStartFilePos();
                        $end = $attributeItem->key->getEndFilePos();

                        if ($start < 0 || $end < 0) {
                            continue;
                        }

                        $attributes[$attributeName] = SourceRange::fromOffsets($contents, $start, $end + 1);
                    }
                }
            }
        }

        ksort($attributes);

        return $attributes;
    }

    private function className(Class_ $class): ?string
    {
        $namespaced = $class->namespacedName ?? null;

        if ($namespaced instanceof Node\Name) {
            return ltrim($namespaced->toString(), '\\');
        }

        return is_string($class->name?->toString()) ? ltrim($class->name->toString(), '\\') : null;
    }
}

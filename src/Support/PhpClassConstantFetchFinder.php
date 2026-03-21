<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

use function array_values;
use function ksort;
use function ltrim;
use function strtolower;

final class PhpClassConstantFetchFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @return list<PhpClassConstantFetch>
     */
    public function find(string $projectRoot): array
    {
        return $this->analysisCache->remember('php-class-constant-fetches', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $references = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                foreach ($this->findInFile($filePath) as $reference) {
                    $references[] = $reference;
                }
            }

            usort(
                $references,
                static fn(PhpClassConstantFetch $left, PhpClassConstantFetch $right): int => (
                    [
                        $left->filePath,
                        $left->range->startLine,
                        $left->range->startColumn,
                        $left->className,
                        $left->constantName,
                    ] <=> [
                        $right->filePath,
                        $right->range->startLine,
                        $right->range->startColumn,
                        $right->className,
                        $right->constantName,
                    ]
                ),
            );

            return $references;
        });
    }

    /**
     * @return list<PhpClassConstantFetch>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }

        $references = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, ClassConstFetch::class) as $fetch) {
            if (!$fetch->class instanceof Name || !$fetch->name instanceof Identifier) {
                continue;
            }

            if (strtolower($fetch->name->toString()) === 'class') {
                continue;
            }

            $resolved = $fetch->class->getAttribute('resolvedName');
            $className = $resolved instanceof Name ? $resolved->toString() : $fetch->class->toString();
            $className = ltrim($className, '\\');

            if ($className === '') {
                continue;
            }

            $start = $fetch->name->getStartFilePos();
            $end = $fetch->name->getEndFilePos();

            if ($start < 0 || $end < 0) {
                continue;
            }

            $key = $filePath . "\0" . $className . "\0" . $fetch->name->toString() . "\0" . $start;
            $references[$key] = new PhpClassConstantFetch(
                filePath: $filePath,
                className: $className,
                constantName: $fetch->name->toString(),
                range: SourceRange::fromOffsets($contents, $start, $end + 1),
            );
        }

        ksort($references);

        return array_values($references);
    }
}

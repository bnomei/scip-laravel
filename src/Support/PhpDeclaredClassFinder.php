<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;

use function array_values;
use function ksort;
use function ltrim;

final class PhpDeclaredClassFinder
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @param list<string> $roots
     * @return list<PhpDeclaredClass>
     */
    public function findInRoots(array $roots): array
    {
        $key = implode("\0", $roots);

        return $this->analysisCache->remember('php-declared-classes', $key, function () use ($roots): array {
            $classes = [];

            foreach ($this->analysisCache->phpFilesInRoots($roots) as $filePath) {
                foreach ($this->findInFile($filePath) as $declaration) {
                    $classes[$declaration->className] = $declaration;
                }
            }

            ksort($classes);

            return array_values($classes);
        });
    }

    /**
     * @return list<PhpDeclaredClass>
     */
    private function findInFile(string $filePath): array
    {
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($ast === null) {
            return [];
        }

        $declarations = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, ClassLike::class) as $classLike) {
            $name = $classLike->namespacedName ?? null;

            if (!$name instanceof Name) {
                continue;
            }

            $className = ltrim($name->toString(), '\\');

            if ($className === '') {
                continue;
            }

            $declarations[] = new PhpDeclaredClass(
                className: $className,
                filePath: $filePath,
                lineNumber: $classLike->getStartLine(),
            );
        }

        return $declarations;
    }
}

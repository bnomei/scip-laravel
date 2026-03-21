<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

use function array_fill_keys;
use function array_map;
use function ltrim;
use function strtolower;

final class PhpLiteralInstantiationFinder
{
    /**
     * @var array<string, list<PhpLiteralInstantiation>>
     */
    private static array $projectCache = [];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public static function reset(): void
    {
        self::$projectCache = [];
    }

    /**
     * @param list<string> $classes
     * @return list<PhpLiteralInstantiation>
     */
    public function find(string $projectRoot, array $classes): array
    {
        return $this->findInFiles($this->analysisCache->projectPhpFiles($projectRoot), $classes);
    }

    /**
     * @param list<string> $filePaths
     * @param list<string> $classes
     * @return list<PhpLiteralInstantiation>
     */
    public function findInFiles(array $filePaths, array $classes): array
    {
        $classes = array_fill_keys(array_map(static fn(string $className): string => strtolower(ltrim(
            $className,
            '\\',
        )), $classes), true);

        if ($classes === []) {
            return [];
        }

        $allInstantiations = $this->projectInstantiations($filePaths);
        $instantiations = [];

        foreach ($allInstantiations as $instantiation) {
            if (isset($classes[$instantiation->className])) {
                $instantiations[] = $instantiation;
            }
        }

        return $instantiations;
    }

    /**
     * @param array<string, true> $classes
     * @return list<PhpLiteralInstantiation>
     */
    private function projectInstantiations(array $filePaths): array
    {
        $projectKey = implode("\0", $filePaths);

        return $this->analysisCache->remember('php-literal-instantiations', $projectKey, function () use (
            $filePaths,
            $projectKey,
        ): array {
            $instantiations = [];

            foreach ($filePaths as $filePath) {
                foreach ($this->findInFile($filePath) as $instantiation) {
                    $instantiations[] = $instantiation;
                }
            }

            return self::$projectCache[$projectKey] = $instantiations;
        });
    }

    /**
     * @return list<PhpLiteralInstantiation>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }

        $instantiations = [];

        foreach ($this->nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof New_) as $node) {
            $instantiation = $this->matchInstantiation($node, $filePath, $contents);

            if ($instantiation !== null) {
                $instantiations[] = $instantiation;
            }
        }

        return $instantiations;
    }

    private function matchInstantiation(New_ $new, string $filePath, string $contents): ?PhpLiteralInstantiation
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        $resolvedClass = $new->class->getAttribute('resolvedName');
        $className = $resolvedClass instanceof Name ? $resolvedClass->toString() : $new->class->toString();
        $className = strtolower(ltrim($className, '\\'));

        $argument = $new->getArgs()[0] ?? null;

        if ($argument === null || !$argument->value instanceof String_) {
            return null;
        }

        $start = $argument->value->getStartFilePos();
        $end = $argument->value->getEndFilePos();

        if ($start < 0 || $end < 0) {
            return null;
        }

        return new PhpLiteralInstantiation(
            filePath: $filePath,
            className: $className,
            literal: $argument->value->value,
            range: SourceRange::fromOffsets($contents, $start + 1, $end),
        );
    }
}

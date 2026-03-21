<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

use function array_merge;
use function is_dir;
use function ltrim;
use function strtolower;

final class PhpAuthorizationReferenceFinder
{
    /**
     * @var array<string, list<PhpAuthorizationReference>>
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
     * @return list<PhpAuthorizationReference>
     */
    public function find(string $projectRoot): array
    {
        if (isset(self::$projectCache[$projectRoot])) {
            return self::$projectCache[$projectRoot];
        }

        $references = [];

        foreach ($this->analysisCache->phpFilesInRoots([
            $projectRoot . '/app/Http/Controllers',
            $projectRoot . '/app/Http/Requests',
            $projectRoot . '/app/Livewire',
        ]) as $filePath) {
            $references = array_merge($references, $this->findInFile($filePath));
        }

        return self::$projectCache[$projectRoot] = $references;
    }

    /**
     * @return list<PhpAuthorizationReference>
     */
    private function findInFile(string $filePath): array
    {
        $contents = $this->analysisCache->contents($filePath);
        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($contents === null || $ast === null) {
            return [];
        }
        $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);

        if (!$class instanceof Class_) {
            return [];
        }

        $references = [];

        foreach ($class->getMethods() as $method) {
            $references = array_merge($references, $this->methodReferences($method, $filePath, $contents));
        }

        return $references;
    }

    /**
     * @return list<PhpAuthorizationReference>
     */
    private function methodReferences(ClassMethod $method, string $filePath, string $contents): array
    {
        $references = [];

        foreach ($this->nodeFinder->find(
            (array) $method->stmts,
            static fn(Node $node): bool => $node instanceof MethodCall || $node instanceof StaticCall,
        ) as $call) {
            $reference = $call instanceof StaticCall
                ? $this->staticCallReference($call, $method, $filePath, $contents)
                : $this->methodCallReference($call, $method, $filePath, $contents);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    private function staticCallReference(
        StaticCall $call,
        ClassMethod $method,
        string $filePath,
        string $contents,
    ): ?PhpAuthorizationReference {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }

        $resolved = $call->class->getAttribute('resolvedName');
        $className = $resolved instanceof Name
            ? ltrim($resolved->toString(), '\\')
            : ltrim($call->class->toString(), '\\');
        $methodName = strtolower($call->name->toString());

        if (
            $className !== 'Illuminate\\Support\\Facades\\Gate'
            || !in_array($methodName, ['authorize', 'allows', 'denies', 'inspect'], true)
        ) {
            return null;
        }

        return $this->abilityReference($call->getArgs()[0]->value ?? null, $method, $filePath, $contents);
    }

    private function methodCallReference(
        MethodCall $call,
        ClassMethod $method,
        string $filePath,
        string $contents,
    ): ?PhpAuthorizationReference {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        $name = strtolower($call->name->toString());

        if ($name === 'authorize' && $call->var instanceof Variable && $call->var->name === 'this') {
            return $this->abilityReference($call->getArgs()[0]->value ?? null, $method, $filePath, $contents);
        }

        return null;
    }

    private function abilityReference(
        mixed $expr,
        ClassMethod $method,
        string $filePath,
        string $contents,
    ): ?PhpAuthorizationReference {
        if (!$expr instanceof String_ || $expr->value === '') {
            return null;
        }

        $start = $expr->getStartFilePos();
        $end = $expr->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < 0) {
            return null;
        }

        return new PhpAuthorizationReference(
            filePath: $filePath,
            ability: $expr->value,
            range: SourceRange::fromOffsets($contents, $start + 1, $end),
            methodName: $method->name->toString(),
            methodLine: $method->getStartLine(),
        );
    }
}

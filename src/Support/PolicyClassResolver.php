<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

use function file_exists;
use function is_dir;
use function ltrim;
use function preg_replace;
use function strtolower;

final class PolicyClassResolver
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $explicitPolicyMapCache = [];

    /**
     * @var array<string, bool>
     */
    private static array $conventionalExistenceCache = [];

    /**
     * @var array<string, ?string>
     */
    private static array $resolvedPolicyCache = [];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public static function reset(): void
    {
        self::$explicitPolicyMapCache = [];
        self::$conventionalExistenceCache = [];
        self::$resolvedPolicyCache = [];
    }

    public function resolve(string $projectRoot, string $targetClass): ?string
    {
        $cacheKey = $projectRoot . "\0" . $targetClass;

        if (array_key_exists($cacheKey, self::$resolvedPolicyCache)) {
            return self::$resolvedPolicyCache[$cacheKey];
        }

        $explicit = $this->explicitPolicyMap($projectRoot)[$targetClass] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return self::$resolvedPolicyCache[$cacheKey] = $explicit;
        }

        $conventional = $this->conventionalPolicyClass($targetClass);
        $policyPath =
            $projectRoot
            . '/app/Policies/'
            . str_replace('\\', '/', substr($conventional, strlen('App\\Policies\\')))
            . '.php';

        if (!isset(self::$conventionalExistenceCache[$cacheKey])) {
            self::$conventionalExistenceCache[$cacheKey] = file_exists($policyPath);
        }

        return self::$resolvedPolicyCache[$cacheKey] = self::$conventionalExistenceCache[$cacheKey]
            ? $conventional
            : null;
    }

    /**
     * @return array<string, string>
     */
    private function explicitPolicyMap(string $projectRoot): array
    {
        return $this->analysisCache->remember('policy-explicit-map', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $root = $projectRoot . '/app/Providers';

            if (!is_dir($root)) {
                return self::$explicitPolicyMapCache[$projectRoot] = [];
            }

            $map = [];

            foreach ($this->analysisCache->phpFilesInRoots([$root], []) as $filePath) {
                $contents = $this->analysisCache->contents($filePath);
                $ast = $this->analysisCache->resolvedAst($filePath);

                if ($contents === null || $ast === null) {
                    continue;
                }

                foreach ($this->nodeFinder->findInstanceOf($ast, StaticCall::class) as $call) {
                    if (
                        !$call->class instanceof Name
                        || !$call->name instanceof Identifier
                        || ltrim(($call->class->getAttribute('resolvedName') ?? $call->class)->toString(), '\\')
                            !== 'Illuminate\\Support\\Facades\\Gate'
                        || strtolower($call->name->toString()) !== 'policy'
                    ) {
                        continue;
                    }

                    $target = $this->classLiteral($call->getArgs()[0] ?? null);
                    $policy = $this->classLiteral($call->getArgs()[1] ?? null);

                    if ($target !== null && $policy !== null) {
                        $map[$target] = $policy;
                    }
                }
            }

            return self::$explicitPolicyMapCache[$projectRoot] = $map;
        });
    }

    private function conventionalPolicyClass(string $targetClass): string
    {
        $basename = preg_replace('/^.*\\\\/', '', $targetClass) ?? $targetClass;

        return 'App\\Policies\\' . $basename . 'Policy';
    }

    private function classLiteral(?Arg $argument): ?string
    {
        $expr = $argument?->value;

        if (
            !$expr instanceof ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'class'
        ) {
            return null;
        }

        $resolved = $expr->class->getAttribute('resolvedName');
        $name = $resolved instanceof Name ? $resolved->toString() : $expr->class->toString();

        $name = ltrim($name, '\\');

        return $name !== '' ? $name : null;
    }
}

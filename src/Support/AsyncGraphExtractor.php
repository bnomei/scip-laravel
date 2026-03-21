<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as LaravelNotification;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionException;

use function array_merge;
use function is_dir;
use function is_string;
use function ltrim;
use function str_contains;
use function strtolower;

final class AsyncGraphExtractor
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @return list<AsyncGraphReference>
     */
    public function references(string $projectRoot): array
    {
        return $this->analysisCache->remember('async-graph-references', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $references = [];

            foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
                $contents = $this->analysisCache->contents($filePath);
                $ast = $this->analysisCache->resolvedAst($filePath);

                if ($contents === null || $ast === null) {
                    continue;
                }

                foreach ($this->nodeFinder->find(
                    $ast,
                    static fn(Node $node): bool => (
                        $node instanceof FuncCall
                        || $node instanceof StaticCall
                        || $node instanceof MethodCall
                    ),
                ) as $call) {
                    $references = array_merge($references, $this->referencesForCall($call, $filePath, $contents));
                }
            }

            return $references;
        });
    }

    /**
     * @return list<AsyncQueueMetadata>
     */
    public function queueMetadata(string $projectRoot): array
    {
        return $this->analysisCache->remember('async-queue-metadata', $projectRoot, function () use (
            $projectRoot,
        ): array {
            $metadata = [];

            foreach ([
                ['root' => $projectRoot . '/app/Jobs', 'type' => 'job'],
                ['root' => $projectRoot . '/app/Listeners', 'type' => 'listener'],
                ['root' => $projectRoot . '/app/Notifications', 'type' => 'notification'],
            ] as $definition) {
                if (!is_dir($definition['root'])) {
                    continue;
                }

                foreach ($this->analysisCache->phpFilesInRoots([$definition['root']]) as $filePath) {
                    $payload = $this->queueMetadataForFile($filePath, $definition['type']);

                    if ($payload !== null) {
                        $metadata[] = $payload;
                    }
                }
            }

            return $metadata;
        });
    }

    /**
     * @return list<AsyncGraphReference>
     */
    private function referencesForCall(Node $call, string $filePath, string $contents): array
    {
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            $function = strtolower(ltrim($call->name->toString(), '\\'));

            return match ($function) {
                'dispatch' => $this->newClassReference(
                    $call->getArgs()[0] ?? null,
                    $filePath,
                    $contents,
                    'job-dispatch',
                ),
                'event' => $this->newClassReference(
                    $call->getArgs()[0] ?? null,
                    $filePath,
                    $contents,
                    'event-dispatch',
                ),
                default => [],
            };
        }

        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier) {
            $class = ltrim(($call->class->getAttribute('resolvedName') ?? $call->class)->toString(), '\\');
            $method = strtolower($call->name->toString());

            if ($method === 'dispatch' && !$this->isFacadeClass($class)) {
                $range = $this->classNameRange($call->class, $contents);

                return $range === null ? [] : [new AsyncGraphReference($filePath, 'job-dispatch', $class, $range)];
            }

            if ($class === 'Illuminate\\Support\\Facades\\Bus') {
                return match ($method) {
                    'dispatch' => $this->newClassReference(
                        $call->getArgs()[0] ?? null,
                        $filePath,
                        $contents,
                        'job-dispatch',
                    ),
                    'batch', 'chain' => $this->newClassesFromArray(
                        $call->getArgs()[0] ?? null,
                        $filePath,
                        $contents,
                        'job-dispatch',
                    ),
                    default => [],
                };
            }

            if ($class === 'Illuminate\\Support\\Facades\\Event') {
                return match ($method) {
                    'dispatch' => $this->newClassReference(
                        $call->getArgs()[0] ?? null,
                        $filePath,
                        $contents,
                        'event-dispatch',
                    ),
                    'listen' => array_merge(
                        $this->classConstReference(
                            $call->getArgs()[0] ?? null,
                            $filePath,
                            $contents,
                            'event-registration',
                        ),
                        $this->classConstReference(
                            $call->getArgs()[1] ?? null,
                            $filePath,
                            $contents,
                            'listener-registration',
                        ),
                    ),
                    default => [],
                };
            }

            if ($class === 'Illuminate\\Support\\Facades\\Notification') {
                return match ($method) {
                    'send', 'sendnow' => $this->newClassReference(
                        $call->getArgs()[1] ?? null,
                        $filePath,
                        $contents,
                        'notification-send',
                    ),
                    default => [],
                };
            }
        }

        if ($call instanceof MethodCall && $call->name instanceof Identifier) {
            $method = strtolower($call->name->toString());

            if ($method === 'notify') {
                return $this->newClassReference($call->getArgs()[0] ?? null, $filePath, $contents, 'notification-send');
            }
        }

        return [];
    }

    private function queueMetadataForFile(string $filePath, string $type): ?AsyncQueueMetadata
    {
        $contents = $this->analysisCache->contents($filePath);

        if ($contents === null) {
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

        $className = $class->namespacedName instanceof Name ? ltrim($class->namespacedName->toString(), '\\') : null;

        if ($className === null) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            return null;
        }

        $documentation = [];

        if ($reflection->implementsInterface(ShouldQueue::class)) {
            $documentation[] = match ($type) {
                'job' => 'Laravel queued job',
                'listener' => 'Laravel queued listener',
                'notification' => 'Laravel queued notification',
                default => 'Laravel queued class',
            };
        }

        if ($type === 'notification' && $reflection->isSubclassOf(LaravelNotification::class)) {
            $documentation[] = 'Laravel notification';
        }

        $middleware = [];

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== 'middleware') {
                continue;
            }

            $middleware = array_merge($middleware, $this->middlewareReferences($method, $contents));
        }

        return $documentation !== [] || $middleware !== []
            ? new AsyncQueueMetadata($className, $filePath, $middleware, $documentation)
            : null;
    }

    /**
     * @return list<array{class: string, range: SourceRange}>
     */
    private function middlewareReferences(ClassMethod $method, string $contents): array
    {
        $references = [];

        foreach ($this->nodeFinder->findInstanceOf((array) $method->stmts, Array_::class) as $array) {
            foreach ($array->items as $item) {
                if ($item === null) {
                    continue;
                }

                $literal = $this->classLiteral($item->value, $contents);

                if ($literal !== null) {
                    $references[] = $literal;
                }
            }
        }

        return $references;
    }

    /**
     * @return list<AsyncGraphReference>
     */
    private function newClassReference(?Arg $argument, string $filePath, string $contents, string $kind): array
    {
        $literal = $this->classLiteral($argument?->value, $contents);

        return (
            $literal === null ? [] : [new AsyncGraphReference($filePath, $kind, $literal['class'], $literal['range'])]
        );
    }

    /**
     * @return list<AsyncGraphReference>
     */
    private function classConstReference(?Arg $argument, string $filePath, string $contents, string $kind): array
    {
        $literal = $this->classConstLiteral($argument?->value, $contents);

        return (
            $literal === null ? [] : [new AsyncGraphReference($filePath, $kind, $literal['class'], $literal['range'])]
        );
    }

    /**
     * @return list<AsyncGraphReference>
     */
    private function newClassesFromArray(?Arg $argument, string $filePath, string $contents, string $kind): array
    {
        $array = $argument?->value;

        if (!$array instanceof Array_) {
            return [];
        }

        $references = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            $literal = $this->classLiteral($item->value, $contents);

            if ($literal !== null) {
                $references[] = new AsyncGraphReference($filePath, $kind, $literal['class'], $literal['range']);
            }
        }

        return $references;
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function classLiteral(mixed $expr, string $contents): ?array
    {
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $class = ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\');
            $range = $this->classNameRange($expr->class, $contents);

            return $class !== '' && $range !== null ? ['class' => $class, 'range' => $range] : null;
        }

        return $this->classConstLiteral($expr, $contents);
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function classConstLiteral(mixed $expr, string $contents): ?array
    {
        if (
            !$expr instanceof Expr\ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'class'
        ) {
            return null;
        }

        $class = ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\');
        $range = $this->classNameRange($expr->class, $contents);

        return $class !== '' && $range !== null ? ['class' => $class, 'range' => $range] : null;
    }

    private function classNameRange(Name $name, string $contents): ?SourceRange
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
    }

    private function isFacadeClass(string $className): bool
    {
        return str_contains($className, 'Illuminate\\Support\\Facades\\');
    }
}

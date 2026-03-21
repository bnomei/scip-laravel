<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Console\Command as LaravelCommand;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionException;

use function array_merge;
use function array_reverse;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function ltrim;
use function sort;
use function str_contains;
use function strtolower;

final class ConsoleGraphExtractor
{
    private NodeFinder $nodeFinder;

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    private readonly ProjectPhpAnalysisCache $analysisCache;

    /**
     * @return list<ConsoleCommandDefinition>
     */
    public function commandDefinitions(string $projectRoot): array
    {
        return array_merge(
            $this->commandClassDefinitions($projectRoot),
            $this->closureCommandDefinitions($projectRoot),
        );
    }

    /**
     * @return list<ConsoleScheduleReference>
     */
    public function scheduleReferences(string $projectRoot): array
    {
        $consolePath = $projectRoot . '/routes/console.php';

        if (!is_file($consolePath)) {
            return [];
        }

        $contents = $this->analysisCache->contents($consolePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $ast = $this->analysisCache->resolvedAst($consolePath);

        if ($ast === null) {
            return [];
        }

        $references = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, Expression::class) as $statement) {
            $reference = $this->scheduleReference($statement->expr, $consolePath, $contents);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return list<ConsoleCommandDefinition>
     */
    private function commandClassDefinitions(string $projectRoot): array
    {
        $root = $projectRoot . '/app/Console/Commands';

        if (!is_dir($root)) {
            return [];
        }

        $definitions = [];

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

            $className = $class->namespacedName instanceof Name
                ? ltrim($class->namespacedName->toString(), '\\')
                : null;

            if ($className === null) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (ReflectionException) {
                continue;
            }

            if (!$reflection->isSubclassOf(LaravelCommand::class)) {
                continue;
            }

            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() !== 'signature' || !$prop->default instanceof String_) {
                        continue;
                    }

                    $range = $this->stringRange($prop->default, $contents);

                    if ($range === null || $prop->default->value === '') {
                        continue;
                    }

                    $definitions[] = new ConsoleCommandDefinition(
                        filePath: $filePath,
                        signature: $prop->default->value,
                        range: $range,
                        className: $className,
                    );
                }
            }
        }

        return $definitions;
    }

    /**
     * @return list<ConsoleCommandDefinition>
     */
    private function closureCommandDefinitions(string $projectRoot): array
    {
        $consolePath = $projectRoot . '/routes/console.php';

        if (!is_file($consolePath)) {
            return [];
        }

        $contents = $this->analysisCache->contents($consolePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $ast = $this->analysisCache->resolvedAst($consolePath);

        if ($ast === null) {
            return [];
        }

        $definitions = [];

        foreach ($this->nodeFinder->findInstanceOf($ast, StaticCall::class) as $call) {
            if (
                !$call->class instanceof Name
                || !$call->name instanceof Identifier
                || ltrim(($call->class->getAttribute('resolvedName') ?? $call->class)->toString(), '\\')
                    !== 'Illuminate\\Support\\Facades\\Artisan'
                || strtolower($call->name->toString()) !== 'command'
            ) {
                continue;
            }

            $signature = $call->getArgs()[0]->value ?? null;

            if (!$signature instanceof String_ || $signature->value === '') {
                continue;
            }

            $range = $this->stringRange($signature, $contents);

            if ($range === null) {
                continue;
            }

            $definitions[] = new ConsoleCommandDefinition(
                filePath: $consolePath,
                signature: $signature->value,
                range: $range,
                className: null,
            );
        }

        return $definitions;
    }

    private function scheduleReference(Expr $expr, string $filePath, string $contents): ?ConsoleScheduleReference
    {
        $chain = $this->callChain($expr);

        if ($chain === []) {
            return null;
        }

        $root = $chain[0];

        if (
            !$root instanceof StaticCall
            || !$root->class instanceof Name
            || !$root->name instanceof Identifier
            || ltrim(($root->class->getAttribute('resolvedName') ?? $root->class)->toString(), '\\')
                !== 'Illuminate\\Support\\Facades\\Schedule'
        ) {
            return null;
        }

        $rootMethod = strtolower($root->name->toString());
        $documentation = $this->scheduleDocumentation($chain);

        return match ($rootMethod) {
            'command' => $this->commandScheduleReference($root, $filePath, $contents, $documentation),
            'job' => $this->jobScheduleReference($root, $filePath, $contents, $documentation),
            'call' => $this->callableScheduleReference($root, $filePath, $contents, $documentation),
            default => null,
        };
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return list<string>
     */
    private function scheduleDocumentation(array $chain): array
    {
        $documentation = [];

        foreach (array_slice($chain, 1) as $call) {
            if (!$call->name instanceof Identifier) {
                continue;
            }

            $method = $call->name->toString();
            $args = [];

            foreach ($call->getArgs() as $argument) {
                if ($argument->value instanceof String_) {
                    $args[] = $argument->value->value;
                }
            }

            $documentation[] = $args === []
                ? 'Laravel schedule: ' . $method
                : 'Laravel schedule: ' . $method . '(' . implode(', ', $args) . ')';
        }

        return $documentation;
    }

    /**
     * @param list<string> $documentation
     */
    private function commandScheduleReference(
        StaticCall $call,
        string $filePath,
        string $contents,
        array $documentation,
    ): ?ConsoleScheduleReference {
        $signature = $call->getArgs()[0]->value ?? null;

        if (!$signature instanceof String_) {
            return null;
        }

        $range = $this->stringRange($signature, $contents);

        return $range === null
            ? null
            : new ConsoleScheduleReference(
                filePath: $filePath,
                kind: 'command',
                range: $range,
                documentation: $documentation,
                signature: $signature->value,
            );
    }

    /**
     * @param list<string> $documentation
     */
    private function jobScheduleReference(
        StaticCall $call,
        string $filePath,
        string $contents,
        array $documentation,
    ): ?ConsoleScheduleReference {
        $literal = $this->classLiteral($call->getArgs()[0] ?? null, $contents);

        return $literal === null
            ? null
            : new ConsoleScheduleReference(
                filePath: $filePath,
                kind: 'job',
                range: $literal['range'],
                documentation: $documentation,
                className: $literal['class'],
            );
    }

    /**
     * @param list<string> $documentation
     */
    private function callableScheduleReference(
        StaticCall $call,
        string $filePath,
        string $contents,
        array $documentation,
    ): ?ConsoleScheduleReference {
        $target = $call->getArgs()[0]->value ?? null;

        if (!$target instanceof Array_) {
            return null;
        }

        $class = $this->classConstFromArrayItem($target->items[0]->value ?? null);
        $method = $target->items[1]->value ?? null;
        $range = $method instanceof String_ ? $this->stringRange($method, $contents) : null;

        return $class !== null && $method instanceof String_ && $range !== null
            ? new ConsoleScheduleReference(
                filePath: $filePath,
                kind: 'callable',
                range: $range,
                documentation: $documentation,
                className: $class,
                methodName: $method->value,
            )
            : null;
    }

    /**
     * @param list<MethodCall|StaticCall> $chain
     * @return list<MethodCall|StaticCall>
     */
    private function callChain(Expr $expr): array
    {
        $calls = [];
        $current = $expr;

        while ($current instanceof MethodCall || $current instanceof StaticCall) {
            $calls[] = $current;
            $current = $current instanceof MethodCall ? $current->var : null;
        }

        return array_reverse($calls);
    }

    /**
     * @return ?array{class: string, range: SourceRange}
     */
    private function classLiteral(?Arg $argument, string $contents): ?array
    {
        $expr = $argument?->value;

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $class = ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\');
            $range = $this->nameRange($expr->class, $contents);

            return $class !== '' && $range !== null ? ['class' => $class, 'range' => $range] : null;
        }

        if (
            $expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            $class = ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\');
            $range = $this->nameRange($expr->class, $contents);

            return $class !== '' && $range !== null ? ['class' => $class, 'range' => $range] : null;
        }

        return null;
    }

    private function classConstFromArrayItem(mixed $expr): ?string
    {
        if (
            !$expr instanceof ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'class'
        ) {
            return null;
        }

        return ltrim(($expr->class->getAttribute('resolvedName') ?? $expr->class)->toString(), '\\');
    }

    private function stringRange(String_ $string, string $contents): ?SourceRange
    {
        $start = $string->getStartFilePos();
        $end = $string->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start + 1, $end);
    }

    private function nameRange(Name $name, string $contents): ?SourceRange
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Blade\VoltBladePreamble;
use Bnomei\ScipLaravel\Blade\VoltBladePreambleParser;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function preg_match_all;
use function preg_quote;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

final class VoltBladeModelMemberReferenceFinder
{
    /**
     * @var list<string>
     */
    private const WRITE_METHODS = [
        'fill',
        'forceFill',
        'update',
    ];

    private Parser $parser;
    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(
        private readonly VoltBladePreambleParser $preambleParser = new VoltBladePreambleParser(),
        ?BladeRuntimeCache $bladeCache = null,
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param array<string, true> $knownModels
     * @param array<string, array<string, string>> $externalScopesByFile
     * @return list<PhpModelMemberReference>
     */
    public function find(string $projectRoot, array $knownModels, array $externalScopesByFile = []): array
    {
        $references = [];

        foreach ($this->bladeFiles($projectRoot) as $filePath) {
            foreach ($this->findInFile($filePath, $knownModels, $externalScopesByFile[$filePath] ?? []) as $reference) {
                $references[] = $reference;
            }
        }

        usort(
            $references,
            static fn(PhpModelMemberReference $left, PhpModelMemberReference $right): int => (
                [
                    $left->filePath,
                    $left->range->startLine,
                    $left->range->startColumn,
                    $left->memberName,
                ] <=> [
                    $right->filePath,
                    $right->range->startLine,
                    $right->range->startColumn,
                    $right->memberName,
                ]
            ),
        );

        return $references;
    }

    /**
     * @param array<string, true> $knownModels
     * @param array<string, string> $externalScope
     * @return list<PhpModelMemberReference>
     */
    private function findInFile(string $filePath, array $knownModels, array $externalScope): array
    {
        $contents = $this->bladeCache->contents($filePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $preamble = $this->preambleParser->parse($contents);

        $scope = $preamble instanceof VoltBladePreamble ? $this->scopeFromPreamble($preamble, $knownModels) : [];
        $scope = $this->mergeScopes($scope, $externalScope);

        if ($scope === []) {
            return [];
        }

        $references = [];
        $bodyOffset = $preamble?->bodyOffset ?? 0;

        if ($preamble instanceof VoltBladePreamble) {
            $this->collectPreambleReferences($references, $filePath, $contents, $preamble, $scope);
        }

        $body = substr($contents, $bodyOffset);

        if ($body === '') {
            ksort($references);

            return array_values($references);
        }

        if (strpos($body, '->') === false) {
            ksort($references);

            return array_values($references);
        }

        $ignoredSpans = $this->ignoredSpans($body);

        foreach ($scope as $variable => $modelClass) {
            $this->collectDirectVariableReferences(
                $references,
                $filePath,
                $contents,
                $body,
                $bodyOffset,
                $ignoredSpans,
                $variable,
                $modelClass,
            );
            $this->collectThisPropertyReferences(
                $references,
                $filePath,
                $contents,
                $body,
                $bodyOffset,
                $ignoredSpans,
                $variable,
                $modelClass,
            );
            $this->collectDirectVariableMethodCalls(
                $references,
                $filePath,
                $contents,
                $body,
                $bodyOffset,
                $ignoredSpans,
                $variable,
                $modelClass,
            );
            $this->collectThisPropertyMethodCalls(
                $references,
                $filePath,
                $contents,
                $body,
                $bodyOffset,
                $ignoredSpans,
                $variable,
                $modelClass,
            );
        }

        ksort($references);

        return array_values($references);
    }

    /**
     * @param array<string, true> $knownModels
     * @return array<string, string>
     */
    private function scopeFromPreamble(VoltBladePreamble $preamble, array $knownModels): array
    {
        $scope = [];
        $conflicts = [];

        foreach ([
            $preamble->propertyTypes,
            $preamble->mountParameterTypes,
            $preamble->computedPropertyTypes,
        ] as $typeMap) {
            foreach ($typeMap as $name => $className) {
                if (!isset($knownModels[$className]) || isset($conflicts[$name])) {
                    continue;
                }

                if (isset($scope[$name]) && $scope[$name] !== $className) {
                    unset($scope[$name]);
                    $conflicts[$name] = true;

                    continue;
                }

                $scope[$name] = $className;
            }
        }

        ksort($scope);

        return $scope;
    }

    /**
     * @param array<string, string> $baseScope
     * @param array<string, string> $externalScope
     * @return array<string, string>
     */
    private function mergeScopes(array $baseScope, array $externalScope): array
    {
        $scope = $baseScope;
        $conflicts = [];

        foreach ($externalScope as $name => $modelClass) {
            if (isset($conflicts[$name])) {
                continue;
            }

            if (isset($scope[$name]) && $scope[$name] !== $modelClass) {
                unset($scope[$name]);
                $conflicts[$name] = true;

                continue;
            }

            $scope[$name] = $modelClass;
        }

        ksort($scope);

        return $scope;
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param array<string, string> $scope
     */
    private function collectPreambleReferences(
        array &$references,
        string $filePath,
        string $contents,
        VoltBladePreamble $preamble,
        array $scope,
    ): void {
        $php = substr($contents, 0, $preamble->bodyOffset);

        if ($php === '') {
            return;
        }

        try {
            $ast = $this->parser->parse($php);
        } catch (Error) {
            return;
        }

        if (!is_array($ast)) {
            return;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $ast = $traverser->traverse($ast);

        foreach ($ast as $node) {
            $this->collectPreambleReferencesInNode($references, $node, $filePath, $contents, $scope);
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param array<string, string> $scope
     */
    private function collectPreambleReferencesInNode(
        array &$references,
        Node $node,
        string $filePath,
        string $contents,
        array $scope,
    ): void {
        if (
            ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
            && $node->name instanceof Identifier
        ) {
            $modelClass = $this->modelClassForReceiver($node->var, $scope);
            $range = $this->nodeRange($node->name, $contents);

            if ($modelClass !== null && $range !== null) {
                $write = $this->isWriteContext($node);
                $references[$this->referenceKey($filePath, $range, $node->name->toString(), false, $write)] =
                    new PhpModelMemberReference(
                        filePath: $filePath,
                        modelClass: $modelClass,
                        memberName: $node->name->toString(),
                        range: $range,
                        write: $write,
                    );
            }
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $modelClass = $this->modelClassForReceiver($node->var, $scope);
            $range = $this->nodeRange($node->name, $contents);

            if ($modelClass !== null && $range !== null) {
                $references[$this->referenceKey($filePath, $range, $node->name->toString(), true, false)] =
                    new PhpModelMemberReference(
                        filePath: $filePath,
                        modelClass: $modelClass,
                        memberName: $node->name->toString(),
                        range: $range,
                        write: false,
                        methodCall: true,
                    );
            }

            if (
                $modelClass !== null
                && in_array(strtolower($node->name->toString()), self::WRITE_METHODS, true)
                && isset($node->args[0])
                && $node->args[0]->value instanceof Array_
            ) {
                $this->collectLiteralArrayWrites($references, $filePath, $contents, $modelClass, $node->args[0]->value);
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $this->collectPreambleReferencesInNode($references, $subNode, $filePath, $contents, $scope);
                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $child) {
                if ($child instanceof Node) {
                    $this->collectPreambleReferencesInNode($references, $child, $filePath, $contents, $scope);
                }
            }
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     */
    private function collectLiteralArrayWrites(
        array &$references,
        string $filePath,
        string $contents,
        string $modelClass,
        Array_ $array,
    ): void {
        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem || !$item->key instanceof String_ || $item->key->value === '') {
                continue;
            }

            $range = $this->nodeRange($item->key, $contents);

            if ($range === null) {
                continue;
            }

            $references[$this->referenceKey($filePath, $range, $item->key->value, false, true)] =
                new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $item->key->value,
                    range: $range,
                    write: true,
                );
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param list<array{int, int}> $ignoredSpans
     */
    private function collectDirectVariableReferences(
        array &$references,
        string $filePath,
        string $contents,
        string $body,
        int $bodyOffset,
        array $ignoredSpans,
        string $variable,
        string $modelClass,
    ): void {
        $pattern =
            '/(?<![\w$])\\$' . preg_quote($variable, '/') . '\s*->\s*(?<member>[A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/m';
        $matches = [];
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['member'] ?? [] as [$member, $offset]) {
            if (
                !is_string($member)
                || !is_int($offset)
                || $offset < 0
                || $this->isIgnoredOffset($ignoredSpans, $offset)
            ) {
                continue;
            }

            $range = SourceRange::fromOffsets(
                $contents,
                $bodyOffset + $offset,
                $bodyOffset + $offset + strlen($member),
            );

            $references[$this->referenceKey($filePath, $range, $member, false, false)] = new PhpModelMemberReference(
                filePath: $filePath,
                modelClass: $modelClass,
                memberName: $member,
                range: $range,
                write: false,
            );
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param list<array{int, int}> $ignoredSpans
     */
    private function collectThisPropertyReferences(
        array &$references,
        string $filePath,
        string $contents,
        string $body,
        int $bodyOffset,
        array $ignoredSpans,
        string $property,
        string $modelClass,
    ): void {
        $pattern =
            '/\\$this\s*->\s*' . preg_quote($property, '/') . '\s*->\s*(?<member>[A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/m';
        $matches = [];
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['member'] ?? [] as [$member, $offset]) {
            if (
                !is_string($member)
                || !is_int($offset)
                || $offset < 0
                || $this->isIgnoredOffset($ignoredSpans, $offset)
            ) {
                continue;
            }

            $range = SourceRange::fromOffsets(
                $contents,
                $bodyOffset + $offset,
                $bodyOffset + $offset + strlen($member),
            );

            $references[$this->referenceKey($filePath, $range, $member, false, false)] = new PhpModelMemberReference(
                filePath: $filePath,
                modelClass: $modelClass,
                memberName: $member,
                range: $range,
                write: false,
            );
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param list<array{int, int}> $ignoredSpans
     */
    private function collectDirectVariableMethodCalls(
        array &$references,
        string $filePath,
        string $contents,
        string $body,
        int $bodyOffset,
        array $ignoredSpans,
        string $variable,
        string $modelClass,
    ): void {
        $pattern = '/(?<![\w$])\\$' . preg_quote($variable, '/') . '\s*->\s*(?<member>[A-Za-z_][A-Za-z0-9_]*)\s*\(/m';
        $matches = [];
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['member'] ?? [] as [$member, $offset]) {
            if (
                !is_string($member)
                || !is_int($offset)
                || $offset < 0
                || $this->isIgnoredOffset($ignoredSpans, $offset)
            ) {
                continue;
            }

            $range = SourceRange::fromOffsets(
                $contents,
                $bodyOffset + $offset,
                $bodyOffset + $offset + strlen($member),
            );

            $references[$this->referenceKey($filePath, $range, $member, true, false)] = new PhpModelMemberReference(
                filePath: $filePath,
                modelClass: $modelClass,
                memberName: $member,
                range: $range,
                write: false,
                methodCall: true,
            );
        }
    }

    /**
     * @param array<string, PhpModelMemberReference> $references
     * @param list<array{int, int}> $ignoredSpans
     */
    private function collectThisPropertyMethodCalls(
        array &$references,
        string $filePath,
        string $contents,
        string $body,
        int $bodyOffset,
        array $ignoredSpans,
        string $property,
        string $modelClass,
    ): void {
        $pattern = '/\\$this\s*->\s*' . preg_quote($property, '/') . '\s*->\s*(?<member>[A-Za-z_][A-Za-z0-9_]*)\s*\(/m';
        $matches = [];
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['member'] ?? [] as [$member, $offset]) {
            if (
                !is_string($member)
                || !is_int($offset)
                || $offset < 0
                || $this->isIgnoredOffset($ignoredSpans, $offset)
            ) {
                continue;
            }

            $range = SourceRange::fromOffsets(
                $contents,
                $bodyOffset + $offset,
                $bodyOffset + $offset + strlen($member),
            );

            $references[$this->referenceKey($filePath, $range, $member, true, false)] = new PhpModelMemberReference(
                filePath: $filePath,
                modelClass: $modelClass,
                memberName: $member,
                range: $range,
                write: false,
                methodCall: true,
            );
        }
    }

    /**
     * @param array<string, string> $scope
     */
    private function modelClassForReceiver(Node $receiver, array $scope): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return $scope[$receiver->name] ?? null;
        }

        if (
            ($receiver instanceof PropertyFetch || $receiver instanceof NullsafePropertyFetch)
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
        ) {
            return $scope[$receiver->name->toString()] ?? null;
        }

        return null;
    }

    private function nodeRange(Node $node, string $contents): ?SourceRange
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < 0) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
    }

    private function isWriteContext(PropertyFetch|NullsafePropertyFetch $node): bool
    {
        $parent = $node->getAttribute('parent');

        return (
            $parent instanceof Assign
            && $parent->var === $node
            || $parent instanceof AssignOp
            && $parent->var === $node
            || $parent instanceof PreInc
            && $parent->var === $node
            || $parent instanceof PreDec
            && $parent->var === $node
            || $parent instanceof PostInc
            && $parent->var === $node
            || $parent instanceof PostDec
            && $parent->var === $node
        );
    }

    private function referenceKey(
        string $filePath,
        SourceRange $range,
        string $memberName,
        bool $methodCall,
        bool $write,
    ): string {
        return (
            $filePath
            . ':'
            . $range->startLine
            . ':'
            . $range->startColumn
            . ':'
            . $range->endLine
            . ':'
            . $range->endColumn
            . ':'
            . $memberName
            . ':'
            . (int) $methodCall
            . ':'
            . (int) $write
        );
    }

    /**
     * @return list<array{int, int}>
     */
    private function ignoredSpans(string $contents): array
    {
        $spans = [];

        foreach ([['{{--', '--}}'], ['<!--', '-->']] as [$startToken, $endToken]) {
            $offset = 0;

            while (($start = strpos($contents, $startToken, $offset)) !== false) {
                $end = strpos($contents, $endToken, $start + strlen($startToken));
                $end = $end === false ? strlen($contents) : $end + strlen($endToken);
                $spans[] = [$start, $end];
                $offset = $end;
            }
        }

        usort($spans, static fn(array $left, array $right): int => $left[0] <=> $right[0]);

        return $spans;
    }

    /**
     * @param list<array{int, int}> $spans
     */
    private function isIgnoredOffset(array $spans, int $offset): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset < $start) {
                return false;
            }

            if ($offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }
}

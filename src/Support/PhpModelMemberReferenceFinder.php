<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\UnionType;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function ksort;
use function ltrim;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strtolower;

final class PhpModelMemberReferenceFinder
{
    /**
     * @var list<string>
     */
    private const MODEL_STATIC_FACTORIES = [
        'create',
        'find',
        'findorfail',
        'firstorcreate',
        'firstornew',
        'forcecreate',
        'make',
        'newinstance',
        'newmodelinstance',
        'updateorcreate',
    ];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(?ProjectPhpAnalysisCache $analysisCache = null)
    {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    /**
     * @param array<string, true> $knownModels
     * @param array<string, array<string, string>> $externalScopesByFile
     * @return list<PhpModelMemberReference>
     */
    public function find(string $projectRoot, array $knownModels, array $externalScopesByFile = []): array
    {
        $references = [];

        foreach ($this->analysisCache->projectPhpFiles($projectRoot) as $filePath) {
            $resolvedPath = realpath($filePath) ?: $filePath;

            foreach ($this->findInFile(
                $filePath,
                $knownModels,
                $externalScopesByFile[$resolvedPath] ?? [],
            ) as $reference) {
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
        $contents = $this->analysisCache->contents($filePath);

        if ($contents === null) {
            return [];
        }

        if (!str_contains($contents, '->') && !str_contains($contents, '?->') && !str_contains($contents, '::')) {
            return [];
        }

        $ast = $this->analysisCache->resolvedAst($filePath);

        if ($ast === null) {
            return [];
        }

        $references = [];
        $scope = $this->mergeScopes([], $externalScope);

        $this->walkStatements($ast, $scope, null, $knownModels, $filePath, $contents, $references);

        return $references;
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
     * @param list<Node> $nodes
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     * @return array<string, string>
     */
    private function walkStatements(
        array $nodes,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): array {
        foreach ($nodes as $node) {
            $scope = $this->processStatement(
                $node,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );
        }

        return $scope;
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     * @return array<string, string>
     */
    private function processStatement(
        Node $node,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): array {
        if ($node instanceof Namespace_) {
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            return $scope;
        }

        if ($node instanceof ClassLike) {
            $className = $this->resolvedClassLikeName($node);

            if (is_array($node->stmts)) {
                $this->walkStatements(
                    $node->stmts,
                    $scope,
                    $className,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
            }

            return $scope;
        }

        if ($node instanceof FunctionLike) {
            $childScope = $scope;

            if (
                $node instanceof Node\Stmt\ClassMethod
                && $currentClass !== null
                && isset($knownModels[$currentClass])
                && !$node->isStatic()
            ) {
                $childScope['this'] = $currentClass;
            }

            foreach ($node->getParams() as $parameter) {
                if (!$parameter->var instanceof Variable || !is_string($parameter->var->name)) {
                    continue;
                }

                $modelClass = $this->resolvedModelClassFromTypeHint($parameter->type, $knownModels);

                if ($modelClass !== null) {
                    $childScope[$parameter->var->name] = $modelClass;
                }
            }

            if ($node instanceof Expr\ArrowFunction) {
                $this->collectReferencesInNode(
                    $node->expr,
                    $childScope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );

                return $scope;
            }

            $statements = $node->getStmts();

            if (is_array($statements)) {
                $this->walkStatements(
                    $statements,
                    $childScope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
            }

            return $scope;
        }

        if ($node instanceof If_) {
            $this->collectReferencesInNode(
                $node->cond,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            foreach ($node->elseifs as $elseif) {
                $this->processElseIf($elseif, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
            }

            if ($node->else instanceof Else_) {
                $this->walkStatements(
                    $node->else->stmts,
                    $scope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
            }

            return $scope;
        }

        if ($node instanceof Foreach_) {
            $this->collectReferencesInNode(
                $node->expr,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            return $scope;
        }

        if ($node instanceof For_) {
            foreach (['init', 'cond', 'loop'] as $property) {
                foreach ($node->{$property} as $expr) {
                    $this->collectReferencesInNode(
                        $expr,
                        $scope,
                        $currentClass,
                        $knownModels,
                        $filePath,
                        $contents,
                        $references,
                    );
                }
            }

            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            return $scope;
        }

        if ($node instanceof While_) {
            $this->collectReferencesInNode(
                $node->cond,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            return $scope;
        }

        if ($node instanceof Do_) {
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
            $this->collectReferencesInNode(
                $node->cond,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );

            return $scope;
        }

        if ($node instanceof Switch_) {
            $this->collectReferencesInNode(
                $node->cond,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );

            foreach ($node->cases as $case) {
                $this->processCase($case, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
            }

            return $scope;
        }

        if ($node instanceof TryCatch) {
            $this->walkStatements($node->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

            foreach ($node->catches as $catch) {
                $this->processCatch($catch, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
            }

            if (is_array($node->finally?->stmts)) {
                $this->walkStatements(
                    $node->finally->stmts,
                    $scope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
            }

            return $scope;
        }

        return $this->scanNode($node, $scope, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     */
    private function processElseIf(
        ElseIf_ $elseif,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        $this->collectReferencesInNode(
            $elseif->cond,
            $scope,
            $currentClass,
            $knownModels,
            $filePath,
            $contents,
            $references,
        );
        $this->walkStatements($elseif->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     */
    private function processCase(
        Case_ $case,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        if ($case->cond instanceof Node) {
            $this->collectReferencesInNode(
                $case->cond,
                $scope,
                $currentClass,
                $knownModels,
                $filePath,
                $contents,
                $references,
            );
        }

        $this->walkStatements($case->stmts, $scope, $currentClass, $knownModels, $filePath, $contents, $references);
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     */
    private function processCatch(
        Catch_ $catch,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        $childScope = $scope;

        if (is_string($catch->var?->name)) {
            $modelTypes = [];

            foreach ($catch->types as $type) {
                $candidate = $this->normalizedName($type);

                if ($candidate !== null && isset($knownModels[$candidate])) {
                    $modelTypes[$candidate] = true;
                }
            }

            if (count($modelTypes) === 1) {
                $childScope[$catch->var->name] = array_keys($modelTypes)[0];
            }
        }

        $this->walkStatements(
            $catch->stmts,
            $childScope,
            $currentClass,
            $knownModels,
            $filePath,
            $contents,
            $references,
        );
    }

    /**
     * @param array<string, string> $scope
     * @param list<PhpModelMemberReference> $references
     */
    private function collectReferencesInNode(
        Node $node,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        if ($node instanceof ClassLike || $node instanceof FunctionLike) {
            return;
        }

        $this->collectDirectReference($node, $scope, $currentClass, $knownModels, $filePath, $contents, $references);

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $this->collectReferencesInNode(
                    $subNode,
                    $scope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $child) {
                if ($child instanceof Node) {
                    $this->collectReferencesInNode(
                        $child,
                        $scope,
                        $currentClass,
                        $knownModels,
                        $filePath,
                        $contents,
                        $references,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, string> $referenceScope
     * @param array<string, string> $assignmentScope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     * @return array<string, string>
     */
    private function scanNode(
        Node $node,
        array $referenceScope,
        array $assignmentScope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): array {
        if ($node instanceof ClassLike || $node instanceof FunctionLike) {
            return $assignmentScope;
        }

        $this->collectDirectReference(
            $node,
            $referenceScope,
            $currentClass,
            $knownModels,
            $filePath,
            $contents,
            $references,
        );

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            $modelClass = $this->resolvedModelClassFromExpr($node->expr, $assignmentScope, $knownModels);

            if ($modelClass !== null) {
                $assignmentScope[$node->var->name] = $modelClass;
            } else {
                unset($assignmentScope[$node->var->name]);
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $assignmentScope = $this->scanNode(
                    $subNode,
                    $referenceScope,
                    $assignmentScope,
                    $currentClass,
                    $knownModels,
                    $filePath,
                    $contents,
                    $references,
                );
                continue;
            }

            if (!is_array($subNode)) {
                continue;
            }

            foreach ($subNode as $child) {
                if ($child instanceof Node) {
                    $assignmentScope = $this->scanNode(
                        $child,
                        $referenceScope,
                        $assignmentScope,
                        $currentClass,
                        $knownModels,
                        $filePath,
                        $contents,
                        $references,
                    );
                }
            }
        }

        return $assignmentScope;
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     */
    private function deprecatedCollectDirectReference(
        Node $node,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        if (
            ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
            && $node->name instanceof Identifier
        ) {
            $modelClass = $this->deprecatedResolvedModelClassForReceiver($node->var, $scope);
            $range = $modelClass !== null ? $this->identifierRange($node->name, $contents) : null;

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: $this->isWriteContext($node),
                );
            }

            return;
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $modelClass = $this->deprecatedResolvedModelClassForReceiver($node->var, $scope);
            $range = $modelClass !== null ? $this->identifierRange($node->name, $contents) : null;

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: false,
                    methodCall: true,
                );
            }

            return;
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $modelClass = $this->deprecatedResolvedModelClassForStaticName($node->class, $currentClass, $knownModels);
            $range = $modelClass !== null ? $this->identifierRange($node->name, $contents) : null;

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: false,
                    methodCall: true,
                );
            }

            return;
        }

        if ($node instanceof ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier) {
            if (strtolower($node->name->toString()) === 'class') {
                return;
            }

            $modelClass = $this->deprecatedResolvedModelClassForStaticName($node->class, $currentClass, $knownModels);
            $range = $modelClass !== null ? $this->identifierRange($node->name, $contents) : null;

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: false,
                    constantFetch: true,
                );
            }
        }
    }

    /**
     * @param array<string, string> $scope
     */
    private function deprecatedResolvedModelClassForReceiver(Expr $receiver, array $scope): ?string
    {
        return $receiver instanceof Variable && is_string($receiver->name) ? $scope[$receiver->name] ?? null : null;
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function deprecatedResolvedModelClassForStaticName(
        Name $name,
        ?string $currentClass,
        array $knownModels,
    ): ?string {
        $lower = strtolower($name->toString());

        if (
            ($lower === 'self' || $lower === 'static')
            && $currentClass !== null
            && isset($knownModels[$currentClass])
        ) {
            return $currentClass;
        }

        $candidate = $this->normalizedName($name);

        return $candidate !== null && isset($knownModels[$candidate]) ? $candidate : null;
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function resolvedModelClassFromTypeHint(Node|string|null $type, array $knownModels): ?string
    {
        if ($type instanceof Name) {
            $candidate = $this->normalizedName($type);

            return $candidate !== null && isset($knownModels[$candidate]) ? $candidate : null;
        }

        if ($type instanceof NullableType) {
            return $this->resolvedModelClassFromTypeHint($type->type, $knownModels);
        }

        if ($type instanceof UnionType) {
            $modelTypes = [];

            foreach ($type->types as $innerType) {
                $candidate = $this->resolvedModelClassFromTypeHint($innerType, $knownModels);

                if ($candidate !== null) {
                    $modelTypes[$candidate] = true;
                    continue;
                }

                if ($innerType instanceof Identifier && strtolower($innerType->toString()) === 'null') {
                    continue;
                }

                return null;
            }

            return count($modelTypes) === 1 ? array_keys($modelTypes)[0] : null;
        }

        return null;
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     */
    private function resolvedModelClassFromExpr(Expr $expr, array $scope, array $knownModels): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name) && isset($scope[$expr->name])) {
            return $scope[$expr->name];
        }

        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $candidate = $this->normalizedName($expr->class);

            return $candidate !== null && isset($knownModels[$candidate]) ? $candidate : null;
        }

        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            $candidate = $this->normalizedName($expr->class);

            if ($candidate === null || !isset($knownModels[$candidate])) {
                return null;
            }

            return in_array(strtolower($expr->name->toString()), self::MODEL_STATIC_FACTORIES, true)
                ? $candidate
                : null;
        }

        if ($expr instanceof Ternary) {
            $if = $expr->if instanceof Expr ? $this->resolvedModelClassFromExpr($expr->if, $scope, $knownModels) : null;
            $else = $this->resolvedModelClassFromExpr($expr->else, $scope, $knownModels);

            if ($if !== null && $if === $else) {
                return $if;
            }

            return null;
        }

        if ($expr instanceof Coalesce) {
            $left = $this->resolvedModelClassFromExpr($expr->left, $scope, $knownModels);
            $right = $this->resolvedModelClassFromExpr($expr->right, $scope, $knownModels);

            if ($left !== null && $left === $right) {
                return $left;
            }

            return null;
        }

        return null;
    }

    private function resolvedClassLikeName(ClassLike $node): ?string
    {
        $namespaced = $node->namespacedName ?? null;

        if ($namespaced instanceof Name) {
            return ltrim($namespaced->toString(), '\\');
        }

        return is_string($node->name?->toString()) ? ltrim($node->name->toString(), '\\') : null;
    }

    private function normalizedName(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        $value = ltrim($name->toString(), '\\');

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, string> $scope
     * @param array<string, true> $knownModels
     * @param list<PhpModelMemberReference> $references
     */
    private function collectDirectReference(
        Node $node,
        array $scope,
        ?string $currentClass,
        array $knownModels,
        string $filePath,
        string $contents,
        array &$references,
    ): void {
        if (
            ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
            && $node->name instanceof Identifier
        ) {
            $modelClass = $this->modelClassForReceiver($node->var, $scope);
            $range = $this->identifierRange($node->name, $contents);

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: $this->isWriteContext($node),
                );
            }

            return;
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier) {
            $modelClass = $this->modelClassForReceiver($node->var, $scope);
            $range = $this->identifierRange($node->name, $contents);

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: false,
                    methodCall: true,
                );
            }

            return;
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $modelClass = $this->resolvedModelClassFromStaticTarget($node->class, $currentClass, $knownModels);
            $range = $this->identifierRange($node->name, $contents);

            if ($modelClass !== null && $range !== null) {
                $references[] = new PhpModelMemberReference(
                    filePath: $filePath,
                    modelClass: $modelClass,
                    memberName: $node->name->toString(),
                    range: $range,
                    write: false,
                    methodCall: true,
                );
            }

            return;
        }
    }

    /**
     * @param array<string, string> $scope
     */
    private function modelClassForReceiver(Expr $receiver, array $scope): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name) && isset($scope[$receiver->name])) {
            return $scope[$receiver->name];
        }

        return null;
    }

    /**
     * @param array<string, true> $knownModels
     */
    private function resolvedModelClassFromStaticTarget(Name $class, ?string $currentClass, array $knownModels): ?string
    {
        $candidate = strtolower($class->toString());

        if (
            ($candidate === 'self' || $candidate === 'static')
            && $currentClass !== null
            && isset($knownModels[$currentClass])
        ) {
            return $currentClass;
        }

        $resolved = $this->normalizedName($class);

        return $resolved !== null && isset($knownModels[$resolved]) ? $resolved : null;
    }

    private function identifierRange(Identifier $identifier, string $contents): ?SourceRange
    {
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();

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
}

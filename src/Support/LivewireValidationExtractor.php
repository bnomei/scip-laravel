<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

use function array_merge;
use function array_values;
use function count;
use function implode;
use function is_string;
use function ksort;
use function ltrim;
use function preg_match;
use function strtolower;

final class LivewireValidationExtractor
{
    /**
     * @var list<string>
     */
    private const VALIDATE_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Validate',
    ];

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly NodeFinder $nodeFinder = new NodeFinder(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
    }

    public function extract(string $filePath): ?LivewireValidationExtraction
    {
        return $this->analysisCache->remember('livewire-validation-extraction', $filePath, function () use (
            $filePath,
        ): ?LivewireValidationExtraction {
            $contents = $this->analysisCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
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

            $className = $this->resolvedClassName($class);

            if ($className === null) {
                return null;
            }

            $occurrences = [];
            $metadata = [];

            foreach ($class->getProperties() as $property) {
                $this->collectValidateAttributeOccurrences($occurrences, $property, $contents);
            }

            foreach ($class->getMethods() as $method) {
                $lowerName = strtolower($method->name->toString());

                if ($lowerName === 'rules') {
                    $occurrences = array_merge($occurrences, $this->methodArrayOccurrences($method, $contents));
                }

                if ($lowerName === 'messages') {
                    $metadata = array_merge($metadata, $this->messageMetadata($method, $contents));
                }

                if ($lowerName === 'validationattributes') {
                    $metadata = array_merge($metadata, $this->validationAttributeMetadata($method, $contents));
                }

                $occurrences = array_merge($occurrences, $this->validateCallOccurrences($method, $contents));
                $occurrences = array_merge($occurrences, $this->validatorMakeOccurrences($method, $contents));
                $metadata = array_merge($metadata, $this->validatorMakeMetadata($method, $contents));
            }

            if ($occurrences === [] && $metadata === []) {
                return null;
            }

            return new LivewireValidationExtraction(
                className: $className,
                occurrences: $occurrences,
                metadata: $metadata,
            );
        });
    }

    /**
     * @param list<ValidationKeyOccurrence> $occurrences
     */
    private function collectValidateAttributeOccurrences(
        array &$occurrences,
        Property $property,
        string $contents,
    ): void {
        if (!$property->isPublic() || count($property->props) !== 1) {
            return;
        }

        $propertyName = $property->props[0]->name->toString();
        $range = $this->nodeRange($property->props[0], $contents);

        if ($range === null || $propertyName === '') {
            return;
        }

        foreach ($property->attrGroups as $group) {
            foreach ($this->matchingAttributes($group, self::VALIDATE_ATTRIBUTE_NAMES) as $_attribute) {
                if (!$this->isExactValidationKey($propertyName)) {
                    continue;
                }

                $occurrences[] = new ValidationKeyOccurrence(
                    key: $propertyName,
                    range: $range,
                    syntaxKind: \Scip\SyntaxKind::Identifier,
                );
            }
        }
    }

    /**
     * @return list<ValidationKeyOccurrence>
     */
    private function methodArrayOccurrences(ClassMethod $method, string $contents): array
    {
        $array = $this->returnedArray($method);

        if (!$array instanceof Array_) {
            return [];
        }

        $occurrences = [];

        foreach ($this->literalArrayItems($array, $contents) as $item) {
            if (!$this->isExactValidationKey($item['key'])) {
                continue;
            }

            $occurrences[] = new ValidationKeyOccurrence(
                key: $item['key'],
                range: $item['range'],
                syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
            );
        }

        return $occurrences;
    }

    /**
     * @return list<ValidationKeyOccurrence>
     */
    private function validateCallOccurrences(ClassMethod $method, string $contents): array
    {
        $occurrences = [];

        foreach ($this->nodeFinder->findInstanceOf((array) $method->stmts, MethodCall::class) as $call) {
            if (
                !$call->var instanceof Variable
                || $call->var->name !== 'this'
                || !$call->name instanceof Identifier
                || strtolower($call->name->toString()) !== 'validate'
            ) {
                continue;
            }

            $argument = $call->getArgs()[0] ?? null;

            if (!$argument instanceof Arg || !$argument->value instanceof Array_) {
                continue;
            }

            foreach ($this->literalArrayItems($argument->value, $contents) as $item) {
                if (!$this->isExactValidationKey($item['key'])) {
                    continue;
                }

                $occurrences[] = new ValidationKeyOccurrence(
                    key: $item['key'],
                    range: $item['range'],
                    syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
                );
            }
        }

        return $occurrences;
    }

    /**
     * @return list<ValidationKeyOccurrence>
     */
    private function validatorMakeOccurrences(ClassMethod $method, string $contents): array
    {
        $occurrences = [];

        foreach ($this->nodeFinder->findInstanceOf((array) $method->stmts, StaticCall::class) as $call) {
            if (
                !$call->class instanceof Name
                || strtolower(ltrim($call->class->toString(), '\\')) !== 'illuminate\\support\\facades\\validator'
                || !$call->name instanceof Identifier
                || strtolower($call->name->toString()) !== 'make'
            ) {
                continue;
            }

            $argument = $call->getArgs()[1] ?? null;

            if (!$argument instanceof Arg || !$argument->value instanceof Array_) {
                continue;
            }

            foreach ($this->literalArrayItems($argument->value, $contents) as $item) {
                if (!$this->isExactValidationKey($item['key'])) {
                    continue;
                }

                $occurrences[] = new ValidationKeyOccurrence(
                    key: $item['key'],
                    range: $item['range'],
                    syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
                );
            }
        }

        return $occurrences;
    }

    /**
     * @return list<ValidationKeyMetadata>
     */
    private function validatorMakeMetadata(ClassMethod $method, string $contents): array
    {
        $metadata = [];

        foreach ($this->nodeFinder->findInstanceOf((array) $method->stmts, StaticCall::class) as $call) {
            if (
                !$call->class instanceof Name
                || strtolower(ltrim($call->class->toString(), '\\')) !== 'illuminate\\support\\facades\\validator'
                || !$call->name instanceof Identifier
                || strtolower($call->name->toString()) !== 'make'
            ) {
                continue;
            }

            $messages = $call->getArgs()[2] ?? null;

            if ($messages instanceof Arg && $messages->value instanceof Array_) {
                $metadata = array_merge($metadata, $this->messageMetadataFromArray($messages->value, $contents));
            }

            $attributes = $call->getArgs()[3] ?? null;

            if ($attributes instanceof Arg && $attributes->value instanceof Array_) {
                $metadata = array_merge($metadata, $this->validationAttributeMetadataFromArray(
                    $attributes->value,
                    $contents,
                ));
            }
        }

        return $metadata;
    }

    /**
     * @return list<ValidationKeyMetadata>
     */
    private function messageMetadata(ClassMethod $method, string $contents): array
    {
        $array = $this->returnedArray($method);

        if (!$array instanceof Array_) {
            return [];
        }

        return $this->messageMetadataFromArray($array, $contents);
    }

    /**
     * @return list<ValidationKeyMetadata>
     */
    private function messageMetadataFromArray(Array_ $array, string $contents): array
    {
        $metadata = [];

        foreach ($this->literalArrayItems($array, $contents) as $item) {
            $normalized = $this->normalizedMessageKey($item['key']);
            $documentation = $this->stringValueDocumentation($item['value'], 'Validation message');

            if ($normalized === null || $documentation === null) {
                continue;
            }

            $metadata[] = new ValidationKeyMetadata(
                key: $normalized['key'],
                documentation: [$documentation . ' (' . $normalized['rule'] . '): ' . $item['value']->value],
                range: $item['range'],
                syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
            );
        }

        return $metadata;
    }

    /**
     * @return list<ValidationKeyMetadata>
     */
    private function validationAttributeMetadata(ClassMethod $method, string $contents): array
    {
        $array = $this->returnedArray($method);

        if (!$array instanceof Array_) {
            return [];
        }

        return $this->validationAttributeMetadataFromArray($array, $contents);
    }

    /**
     * @return list<ValidationKeyMetadata>
     */
    private function validationAttributeMetadataFromArray(Array_ $array, string $contents): array
    {
        $metadata = [];

        foreach ($this->literalArrayItems($array, $contents) as $item) {
            if (!$this->isExactValidationKey($item['key']) || !$item['value'] instanceof String_) {
                continue;
            }

            $metadata[] = new ValidationKeyMetadata(
                key: $item['key'],
                documentation: ['Validation attribute: ' . $item['value']->value],
                range: $item['range'],
                syntaxKind: \Scip\SyntaxKind::StringLiteralKey,
            );
        }

        return $metadata;
    }

    private function returnedArray(ClassMethod $method): ?Array_
    {
        if (!is_array($method->stmts)) {
            return null;
        }

        $return = $this->nodeFinder->findFirstInstanceOf($method->stmts, Return_::class);

        return $return instanceof Return_ && $return->expr instanceof Array_ ? $return->expr : null;
    }

    /**
     * @return list<array{key: string, range: SourceRange, value: Node\Expr}>
     */
    private function literalArrayItems(Array_ $array, string $contents): array
    {
        $items = [];

        foreach ($array->items as $item) {
            if ($item === null || !$item->key instanceof String_) {
                continue;
            }

            $range = $this->nodeRange($item->key, $contents);

            if ($range === null) {
                continue;
            }

            $items[] = [
                'key' => $item->key->value,
                'range' => $range,
                'value' => $item->value,
            ];
        }

        return $items;
    }

    private function normalizedMessageKey(string $key): ?array
    {
        if ($key === '' || !$this->isValidationKeyCandidate($key)) {
            return null;
        }

        $segments = explode('.', $key);

        if (count($segments) < 2) {
            return null;
        }

        $rule = array_pop($segments);
        $fieldKey = implode('.', $segments);

        if (!is_string($rule) || $rule === '' || !$this->isExactValidationKey($fieldKey)) {
            return null;
        }

        return [
            'key' => $fieldKey,
            'rule' => $rule,
        ];
    }

    private function stringValueDocumentation(Node\Expr $value, string $label): ?string
    {
        return $value instanceof String_ ? $label : null;
    }

    /**
     * @return list<Attribute>
     */
    private function matchingAttributes(AttributeGroup $group, array $candidates): array
    {
        $matches = [];

        foreach ($group->attrs as $attribute) {
            $name = $this->normalizedName($attribute->name);

            if ($name !== null && $this->matchesName($name, $candidates)) {
                $matches[] = $attribute;
            }
        }

        return $matches;
    }

    private function nodeRange(Node $node, string $contents): ?SourceRange
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < 0) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start + 1, $end);
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        if (!$class->namespacedName instanceof Name) {
            return null;
        }

        return ltrim($class->namespacedName->toString(), '\\');
    }

    private function normalizedName(Node $node): ?string
    {
        if (!$node instanceof Name) {
            return null;
        }

        $resolved = $node->getAttribute('resolvedName');
        $name = $resolved instanceof Name ? $resolved->toString() : $node->toString();
        $name = ltrim($name, '\\');

        return $name !== '' ? $name : null;
    }

    /**
     * @param list<string> $candidates
     */
    private function matchesName(string $name, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (strtolower($name) === strtolower($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isExactValidationKey(string $key): bool
    {
        return $this->isValidationKeyCandidate($key) && !str_contains($key, '*');
    }

    private function isValidationKeyCandidate(string $key): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $key) === 1;
    }
}

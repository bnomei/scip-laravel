<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function ltrim;
use function preg_replace;
use function sort;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;

final class VoltBladePreambleParser
{
    /**
     * @var list<string>
     */
    private const VOLT_COMPONENT_NAMES = [
        'Livewire\\Volt\\Component',
    ];

    /**
     * @var list<string>
     */
    private const VOLT_DECLARATION_FUNCTIONS = [
        'action',
        'computed',
        'layout',
        'mount',
        'state',
        'title',
        'usesFileUploads',
        'usesPagination',
        'Livewire\\Volt\\action',
        'Livewire\\Volt\\computed',
        'Livewire\\Volt\\layout',
        'Livewire\\Volt\\mount',
        'Livewire\\Volt\\state',
        'Livewire\\Volt\\title',
        'Livewire\\Volt\\usesFileUploads',
        'Livewire\\Volt\\usesPagination',
    ];

    /**
     * @var list<string>
     */
    private const MOUNT_FUNCTION_NAMES = [
        'mount',
        'Livewire\\Volt\\mount',
    ];

    /**
     * @var list<string>
     */
    private const STATE_FUNCTION_NAMES = [
        'state',
        'Livewire\\Volt\\state',
    ];

    /**
     * @var list<string>
     */
    private const COMPUTED_FUNCTION_NAMES = [
        'computed',
        'Livewire\\Volt\\computed',
    ];

    /**
     * @var list<string>
     */
    private const LAYOUT_FUNCTION_NAMES = [
        'layout',
        'Livewire\\Volt\\layout',
    ];

    /**
     * @var list<string>
     */
    private const TITLE_FUNCTION_NAMES = [
        'title',
        'Livewire\\Volt\\title',
    ];

    /**
     * @var list<string>
     */
    private const COMPUTED_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Computed',
    ];

    /**
     * @var list<string>
     */
    private const LOCKED_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Locked',
    ];

    /**
     * @var list<string>
     */
    private const SESSION_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Session',
    ];

    /**
     * @var list<string>
     */
    private const URL_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Url',
    ];

    /**
     * @var list<string>
     */
    private const VALIDATE_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Validate',
    ];

    /**
     * @var list<string>
     */
    private const ON_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\On',
    ];

    /**
     * @var list<string>
     */
    private const LAYOUT_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Layout',
    ];

    /**
     * @var list<string>
     */
    private const TITLE_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Title',
    ];

    private Parser $parser;

    private NodeFinder $nodeFinder;

    private readonly BladeRuntimeCache $bladeCache;

    public function __construct(?BladeRuntimeCache $bladeCache = null)
    {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function parse(string $contents): ?VoltBladePreamble
    {
        return $this->bladeCache->remember(
            'volt-preamble',
            sha1($contents),
            fn(): ?VoltBladePreamble => $this->parseUncached($contents),
        );
    }

    private function parseUncached(string $contents): ?VoltBladePreamble
    {
        $preamble = $this->extractLeadingPreamble($contents);

        if ($preamble === null) {
            return null;
        }

        try {
            $ast = $this->parser->parse($preamble['php']);
        } catch (Error) {
            return null;
        }

        if (!is_array($ast)) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
        $ast = $traverser->traverse($ast);
        $propertyTypes = [];
        $mountParameterTypes = [];
        $computedPropertyTypes = [];
        $stateNames = [];
        $propertyRanges = [];
        $methodRanges = [];
        $propertyMetadata = [];
        $methodMetadata = [];
        $viewMetadata = [];
        $layoutReferences = [];
        $isVolt = false;

        foreach ($this->nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof New_) as $new) {
            if (!$new->class instanceof Class_ || !$this->extendsVoltComponent($new->class)) {
                continue;
            }

            $isVolt = true;

            foreach ($new->class->getProperties() as $property) {
                $this->collectPropertyTypes(
                    $property,
                    $propertyTypes,
                    $propertyRanges,
                    $contents,
                    $preamble['startOffset'],
                );
                $this->collectPropertyMetadata($property, $propertyMetadata);
            }

            foreach ($new->class->getMethods() as $method) {
                $this->collectMountParameterTypes($method, $mountParameterTypes);
                $this->collectComputedPropertyTypes($method, $computedPropertyTypes);
                $this->collectMethodRanges($method, $methodRanges, $contents, $preamble['startOffset']);
                $this->collectMethodMetadata($method, $methodMetadata);
            }

            $this->collectClassMetadata(
                $new->class,
                $viewMetadata,
                $layoutReferences,
                $contents,
                $preamble['startOffset'],
            );
        }

        foreach ($this->nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof Assign) as $assign) {
            $this->collectComputedPropertyFromAssignment(
                $assign,
                $propertyRanges,
                $propertyMetadata,
                $contents,
                $preamble['startOffset'],
            );
        }

        foreach ($this->nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof FuncCall) as $call) {
            $functionName = $this->normalizedName($call->name);

            if ($functionName === null || !$this->matchesName($functionName, self::VOLT_DECLARATION_FUNCTIONS)) {
                continue;
            }

            $isVolt = true;

            if ($this->matchesName($functionName, self::STATE_FUNCTION_NAMES)) {
                $this->collectStateNamesFromCall(
                    $call,
                    $stateNames,
                    $propertyRanges,
                    $contents,
                    $preamble['startOffset'],
                );
            }

            if ($this->matchesName($functionName, self::MOUNT_FUNCTION_NAMES)) {
                $this->collectMountParameterTypesFromCall($call, $mountParameterTypes);
            }

            $this->collectHelperMetadata($call, $viewMetadata, $layoutReferences, $contents, $preamble['startOffset']);
        }

        if (!$isVolt) {
            return null;
        }

        ksort($propertyTypes);
        ksort($propertyMetadata);
        ksort($methodMetadata);

        foreach ($propertyMetadata as &$documentation) {
            sort($documentation);
        }

        unset($documentation);

        foreach ($methodMetadata as &$documentation) {
            sort($documentation);
        }

        unset($documentation);

        ksort($mountParameterTypes);
        ksort($computedPropertyTypes);
        $stateNames = array_keys($stateNames);
        sort($stateNames);
        sort($viewMetadata);

        return new VoltBladePreamble(
            bodyOffset: $preamble['bodyOffset'],
            propertyTypes: $propertyTypes,
            mountParameterTypes: $mountParameterTypes,
            computedPropertyTypes: $computedPropertyTypes,
            stateNames: $stateNames,
            propertyRanges: $propertyRanges,
            methodRanges: $methodRanges,
            propertyMetadata: $propertyMetadata,
            methodMetadata: $methodMetadata,
            viewMetadata: array_values($viewMetadata),
            layoutReferences: $layoutReferences,
        );
    }

    /**
     * @return ?array{php: string, bodyOffset: int, startOffset: int}
     */
    private function extractLeadingPreamble(string $contents): ?array
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                break;
            }

            $offset++;
        }

        if (!str_starts_with(substr($contents, $offset), '<?php')) {
            return null;
        }

        $close = strpos($contents, '?>', $offset + 5);

        if (!is_int($close)) {
            return null;
        }

        return [
            'php' => substr($contents, $offset, $close + 2 - $offset),
            'bodyOffset' => $close + 2,
            'startOffset' => $offset,
        ];
    }

    private function extendsVoltComponent(Class_ $class): bool
    {
        if (!$class->extends instanceof Name) {
            return false;
        }

        $className = $this->normalizedName($class->extends);

        return $className !== null && $this->matchesName($className, self::VOLT_COMPONENT_NAMES);
    }

    /**
     * @param array<string, string> $propertyTypes
     * @param array<string, SourceRange> $propertyRanges
     */
    private function collectPropertyTypes(
        Property $property,
        array &$propertyTypes,
        array &$propertyRanges,
        string $contents,
        int $baseOffset,
    ): void {
        if (!$property->isPublic() || count($property->props) !== 1) {
            return;
        }

        $propertyName = $property->props[0]->name->toString();
        $propertyRanges[$propertyName] ??= $this->nodeRange($property->props[0], $contents, $baseOffset);
        $typeName = $this->normalizedType($property->type);

        if ($typeName !== null) {
            $propertyTypes[$propertyName] = $typeName;
        }
    }

    /**
     * @param array<string, string> $mountParameterTypes
     */
    private function collectMountParameterTypes(ClassMethod $method, array &$mountParameterTypes): void
    {
        if (strtolower($method->name->toString()) !== 'mount') {
            return;
        }

        foreach ($method->getParams() as $parameter) {
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                continue;
            }

            $typeName = $this->normalizedType($parameter->type);

            if ($typeName === null) {
                continue;
            }

            $mountParameterTypes[$parameter->var->name] = $typeName;
        }
    }

    /**
     * @param array<string, string> $computedPropertyTypes
     */
    private function collectComputedPropertyTypes(ClassMethod $method, array &$computedPropertyTypes): void
    {
        if (!$method->isPublic() || $method->isStatic() || $method->getParams() !== []) {
            return;
        }

        $methodName = $method->name->toString();

        if ($methodName === '' || in_array(strtolower($methodName), ['mount', 'render'], true)) {
            return;
        }

        $typeName = $this->normalizedType($method->returnType);

        if ($typeName === null) {
            return;
        }

        $computedPropertyTypes[$methodName] = $typeName;
    }

    /**
     * @param array<string, true> $stateNames
     * @param array<string, SourceRange> $propertyRanges
     */
    private function collectStateNamesFromCall(
        FuncCall $call,
        array &$stateNames,
        array &$propertyRanges,
        string $contents,
        int $baseOffset,
    ): void {
        $argument = $call->getArgs()[0] ?? null;

        if ($argument === null) {
            return;
        }

        if ($argument->value instanceof String_) {
            $stateNames[$argument->value->value] = true;
            $propertyRanges[$argument->value->value] ??= $this->nodeRange($argument->value, $contents, $baseOffset);

            return;
        }

        if (!$argument->value instanceof Array_) {
            return;
        }

        foreach ($argument->value->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof String_) {
                $stateNames[$item->key->value] = true;
                $propertyRanges[$item->key->value] ??= $this->nodeRange($item->key, $contents, $baseOffset);
                continue;
            }

            if ($item->key === null && $item->value instanceof String_) {
                $stateNames[$item->value->value] = true;
                $propertyRanges[$item->value->value] ??= $this->nodeRange($item->value, $contents, $baseOffset);
            }
        }
    }

    /**
     * @param array<string, list<string>> $propertyMetadata
     */
    private function collectPropertyMetadata(Property $property, array &$propertyMetadata): void
    {
        if (!$property->isPublic() || count($property->props) !== 1) {
            return;
        }

        $propertyName = $property->props[0]->name->toString();

        foreach ($this->documentationForAttributeGroups(
            $property->attrGroups,
            propertyContext: true,
        ) as $documentation) {
            $propertyMetadata[$propertyName][] = $documentation;
        }
    }

    /**
     * @param array<string, list<string>> $methodMetadata
     */
    private function collectMethodMetadata(ClassMethod $method, array &$methodMetadata): void
    {
        $methodName = $method->name->toString();

        foreach ($this->documentationForAttributeGroups(
            $method->attrGroups,
            propertyContext: false,
        ) as $documentation) {
            $methodMetadata[$methodName][] = $documentation;
        }
    }

    /**
     * @param array<string> $viewMetadata
     * @param list<BladeLiteralReference> $layoutReferences
     */
    private function collectClassMetadata(
        Class_ $class,
        array &$viewMetadata,
        array &$layoutReferences,
        string $contents,
        int $baseOffset,
    ): void {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null) {
                    continue;
                }

                if ($this->matchesName($attributeName, self::LAYOUT_ATTRIBUTE_NAMES)) {
                    $reference = $this->layoutReferenceFromNode(
                        $attribute->args[0]->value ?? null,
                        $contents,
                        $baseOffset,
                        'livewire-layout',
                    );

                    if ($reference !== null) {
                        $layoutReferences[] = $reference;
                    }
                }

                if ($this->matchesName($attributeName, self::TITLE_ATTRIBUTE_NAMES)) {
                    $title = $this->literalString($attribute->args[0]->value ?? null);

                    if ($title !== null) {
                        $viewMetadata[] = 'Livewire title: ' . $title;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, SourceRange> $propertyRanges
     * @param array<string, list<string>> $propertyMetadata
     */
    private function collectComputedPropertyFromAssignment(
        Assign $assign,
        array &$propertyRanges,
        array &$propertyMetadata,
        string $contents,
        int $baseOffset,
    ): void {
        if (!$assign->var instanceof Variable || !is_string($assign->var->name) || !$assign->expr instanceof FuncCall) {
            return;
        }

        $functionName = $this->normalizedName($assign->expr->name);

        if ($functionName === null || !$this->matchesName($functionName, self::COMPUTED_FUNCTION_NAMES)) {
            return;
        }

        $propertyName = $assign->var->name;
        $propertyRanges[$propertyName] ??= $this->nodeRange($assign->var, $contents, $baseOffset);
        $propertyMetadata[$propertyName][] = 'Livewire computed property';
    }

    /**
     * @param array<string> $viewMetadata
     * @param list<BladeLiteralReference> $layoutReferences
     */
    private function collectHelperMetadata(
        FuncCall $call,
        array &$viewMetadata,
        array &$layoutReferences,
        string $contents,
        int $baseOffset,
    ): void {
        $functionName = $this->normalizedName($call->name);

        if ($functionName === null) {
            return;
        }

        if ($this->matchesName($functionName, self::LAYOUT_FUNCTION_NAMES)) {
            $reference = $this->layoutReferenceFromNode(
                $call->getArgs()[0]->value ?? null,
                $contents,
                $baseOffset,
                'livewire-layout',
            );

            if ($reference !== null) {
                $layoutReferences[] = $reference;
            }
        }

        if ($this->matchesName($functionName, self::TITLE_FUNCTION_NAMES)) {
            $title = $this->literalString($call->getArgs()[0]->value ?? null);

            if ($title !== null) {
                $viewMetadata[] = 'Livewire title: ' . $title;
            }
        }
    }

    /**
     * @param list<AttributeGroup> $attributeGroups
     * @return list<string>
     */
    private function documentationForAttributeGroups(array $attributeGroups, bool $propertyContext): array
    {
        $documentation = [];

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null) {
                    continue;
                }

                if ($propertyContext) {
                    if ($this->matchesName($attributeName, self::LOCKED_ATTRIBUTE_NAMES)) {
                        $documentation[] = 'Livewire locked property';
                        continue;
                    }

                    if ($this->matchesName($attributeName, self::SESSION_ATTRIBUTE_NAMES)) {
                        $documentation[] = 'Livewire session property';
                        continue;
                    }

                    if ($this->matchesName($attributeName, self::URL_ATTRIBUTE_NAMES)) {
                        $documentation[] = 'Livewire URL property';
                        continue;
                    }

                    if ($this->matchesName($attributeName, self::VALIDATE_ATTRIBUTE_NAMES)) {
                        $rule = $this->literalString($attribute->args[0]->value ?? null);
                        $documentation[] = $rule === null ? 'Livewire validation' : 'Livewire validation: ' . $rule;
                        continue;
                    }
                }

                if (!$propertyContext && $this->matchesName($attributeName, self::COMPUTED_ATTRIBUTE_NAMES)) {
                    $documentation[] = 'Livewire computed property';
                    continue;
                }

                if (!$propertyContext && $this->matchesName($attributeName, self::ON_ATTRIBUTE_NAMES)) {
                    foreach ($this->literalStrings($attribute->args[0]->value ?? null) as $eventName) {
                        $documentation[] = 'Livewire event listener: ' . $eventName;
                    }
                }
            }
        }

        return array_values(array_unique($documentation));
    }

    private function layoutReferenceFromNode(
        ?Node $node,
        string $contents,
        int $baseOffset,
        string $directive,
    ): ?BladeLiteralReference {
        if (!$node instanceof String_ || $node->value === '') {
            return null;
        }

        return new BladeLiteralReference(
            domain: 'view',
            directive: $directive,
            literal: $node->value,
            range: $this->nodeRange($node, $contents, $baseOffset),
        );
    }

    private function literalString(?Node $node): ?string
    {
        return $node instanceof String_ && $node->value !== '' ? $node->value : null;
    }

    /**
     * @return list<string>
     */
    private function literalStrings(?Node $node): array
    {
        if ($node instanceof String_ && $node->value !== '') {
            return [$node->value];
        }

        if (!$node instanceof Array_) {
            return [];
        }

        $strings = [];

        foreach ($node->items as $item) {
            if ($item === null || !$item->value instanceof String_ || $item->value->value === '') {
                continue;
            }

            $strings[] = $item->value->value;
        }

        sort($strings);

        return array_values(array_unique($strings));
    }

    /**
     * @param array<string, string> $mountParameterTypes
     */
    private function collectMountParameterTypesFromCall(FuncCall $call, array &$mountParameterTypes): void
    {
        foreach ($call->getArgs() as $argument) {
            $value = $argument->value;

            if (!$value instanceof Closure && !$value instanceof ArrowFunction) {
                continue;
            }

            foreach ($value->getParams() as $parameter) {
                if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)) {
                    continue;
                }

                $typeName = $this->normalizedType($parameter->type);

                if ($typeName === null) {
                    continue;
                }

                $mountParameterTypes[$parameter->var->name] = $typeName;
            }
        }
    }

    /**
     * @param array<string, SourceRange> $methodRanges
     */
    private function collectMethodRanges(
        ClassMethod $method,
        array &$methodRanges,
        string $contents,
        int $baseOffset,
    ): void {
        if (!$method->isPublic() || $method->isStatic()) {
            return;
        }

        $methodName = $method->name->toString();

        if ($methodName === '') {
            return;
        }

        $methodRanges[$methodName] ??= $this->nodeRange($method->name, $contents, $baseOffset);
    }

    private function nodeRange(Node $node, string $contents, int $baseOffset): ?SourceRange
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $baseOffset + $start, $baseOffset + $end + 1);
    }

    private function normalizedType(Node|string|null $type): ?string
    {
        if (!$type instanceof Name) {
            return null;
        }

        return $this->normalizedName($type);
    }

    private function normalizedName(Node $node): ?string
    {
        if (!$node instanceof Name) {
            return null;
        }

        $resolved = $node->getAttribute('resolvedName');
        $className = $resolved instanceof Name ? $resolved->toString() : $node->toString();
        $className = ltrim($className, '\\');

        return $className !== '' ? $className : null;
    }

    /**
     * @param list<string> $candidates
     */
    private function matchesName(string $name, array $candidates): bool
    {
        $normalized = strtolower($name);

        foreach ($candidates as $candidate) {
            if ($normalized === strtolower($candidate)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Livewire;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\LivewireComponentInventoryBuilder;
use Bnomei\ScipLaravel\Blade\BladeLiteralReference;
use Bnomei\ScipLaravel\Blade\BladeRuntimeCache;
use Bnomei\ScipLaravel\Blade\VoltBladePreambleParser;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\SourceRange;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RegexIterator;
use Scip\Document;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

final class LivewireAttributesEnricher implements Enricher
{
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
    private const MODELABLE_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Modelable',
    ];

    /**
     * @var list<string>
     */
    private const REACTIVE_ATTRIBUTE_NAMES = [
        'Livewire\\Attributes\\Reactive',
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

    private NodeFinder $nodeFinder;

    private readonly BladeRuntimeCache $bladeCache;

    private readonly ProjectPhpAnalysisCache $analysisCache;

    public function __construct(
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        private readonly VoltBladePreambleParser $voltPreambleParser = new VoltBladePreambleParser(),
        private readonly LivewireComponentInventoryBuilder $livewireInventoryBuilder = new LivewireComponentInventoryBuilder(),
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        ?BladeRuntimeCache $bladeCache = null,
        ?ProjectPhpAnalysisCache $analysisCache = null,
    ) {
        $this->bladeCache = $bladeCache ?? BladeRuntimeCache::shared();
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    public function feature(): string
    {
        return 'views';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $viewCatalog = $this->viewCatalog($context);
        $livewireInventory = $this->livewireInventoryBuilder->collect($context);
        $symbols = [];
        $occurrences = [];

        foreach ($this->classBackedPayloads($context, $viewCatalog) as $payload) {
            foreach ($payload['symbols'] as $symbol) {
                $symbols[] = $symbol;
            }

            foreach ($payload['occurrences'] as $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        foreach ($this->voltPayloads($context, $livewireInventory, $viewCatalog) as $payload) {
            foreach ($payload['symbols'] as $symbol) {
                $symbols[] = $symbol;
            }

            foreach ($payload['occurrences'] as $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        if ($symbols === [] && $occurrences === []) {
            return IndexPatch::empty();
        }

        return new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @param array{
     *   symbolsByName: array<string, string>,
     *   pathsByName: array<string, string>,
     *   symbolsByPath: array<string, list<string>>
     * } $viewCatalog
     * @return list<array{symbols: list<DocumentSymbolPatch>, occurrences: list<DocumentOccurrencePatch>}>
     */
    private function classBackedPayloads(LaravelContext $context, array $viewCatalog): array
    {
        $root = $context->projectRoot . '/app/Livewire';

        if (!is_dir($root)) {
            return [];
        }

        $payloads = [];

        foreach ($this->phpFiles($root) as $filePath) {
            $payload = $this->classBackedPayload($context, $viewCatalog, $filePath);

            if ($payload !== null) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param array{
     *   symbolsByName: array<string, string>,
     *   pathsByName: array<string, string>,
     *   symbolsByPath: array<string, list<string>>
     * } $viewCatalog
     * @return ?array{symbols: list<DocumentSymbolPatch>, occurrences: list<DocumentOccurrencePatch>}
     */
    private function classBackedPayload(LaravelContext $context, array $viewCatalog, string $filePath): ?array
    {
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

        if ($className === null || !$this->isLivewireComponent($className)) {
            return null;
        }

        $relativePath = $context->relativeProjectPath($filePath);
        $classSymbol = $this->classSymbolResolver->resolve(
            $context->baselineIndex,
            $relativePath,
            $className,
            $class->getStartLine(),
        );
        $renderMethod = $class->getMethod('render');
        $renderSymbol = $renderMethod instanceof ClassMethod
            ? $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $relativePath,
                'render',
                $renderMethod->getStartLine(),
            )
            : null;
        $propertySymbols = [];
        $methodSymbols = [];
        $symbols = [];
        $occurrences = [];

        foreach ($class->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $propertyName = $prop->name->toString();
                $symbol = $this->propertySymbolResolver->resolve(
                    $context->baselineIndex,
                    $relativePath,
                    $className,
                    $propertyName,
                );

                if (is_string($symbol) && $symbol !== '') {
                    $propertySymbols[$propertyName] = $symbol;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            $methodName = $method->name->toString();
            $symbol = $this->methodSymbolResolver->resolve(
                $context->baselineIndex,
                $relativePath,
                $methodName,
                $method->getStartLine(),
            );

            if (is_string($symbol) && $symbol !== '') {
                $methodSymbols[$methodName] = $symbol;
            }
        }

        foreach ($class->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $propertyName = $prop->name->toString();
                $symbol = $propertySymbols[$propertyName] ?? null;

                if (!is_string($symbol) || $symbol === '') {
                    continue;
                }

                $documentation = $this->propertyDocumentation($property->attrGroups);
                $documentation = [
                    ...$documentation,
                    ...$context->surveyor->propertyDocumentation($className, $propertyName),
                ];

                if ($documentation !== []) {
                    $symbols[] = $this->symbolPatch(
                        $relativePath,
                        $symbol,
                        $documentation,
                        $context->surveyor->propertySignatureDocumentation($className, $propertyName),
                    );
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic()) {
                continue;
            }

            $methodName = $method->name->toString();
            $symbol = $methodSymbols[$methodName] ?? null;

            if (!is_string($symbol) || $symbol === '') {
                continue;
            }

            $documentation = $this->methodDocumentation($method->attrGroups);
            $documentation = [
                ...$documentation,
                ...$context->surveyor->methodDocumentation($className, $methodName),
            ];

            if ($documentation !== []) {
                $symbols[] = $this->symbolPatch(
                    $relativePath,
                    $symbol,
                    $documentation,
                    $context->surveyor->methodSignatureDocumentation($className, $methodName),
                );
            }
        }

        if (is_string($classSymbol) && $classSymbol !== '') {
            $titleDocumentation = $this->titleDocumentation($class->attrGroups);

            if ($titleDocumentation !== []) {
                $symbols[] = $this->symbolPatch($relativePath, $classSymbol, $titleDocumentation);
            }
        }

        foreach ($this->attributeLayoutReferences($class->attrGroups, $contents) as $reference) {
            $occurrence = $this->layoutOccurrence($context, $viewCatalog, $relativePath, $reference);

            if ($occurrence !== null) {
                $occurrences[] = $occurrence;
            }
        }

        if ($renderMethod instanceof ClassMethod) {
            if (is_string($renderSymbol) && $renderSymbol !== '') {
                $titleDocumentation = $this->titleDocumentation($renderMethod->attrGroups);

                if ($titleDocumentation !== []) {
                    $symbols[] = $this->symbolPatch($relativePath, $renderSymbol, $titleDocumentation);
                }
            }

            foreach ($this->attributeLayoutReferences($renderMethod->attrGroups, $contents) as $reference) {
                $occurrence = $this->layoutOccurrence($context, $viewCatalog, $relativePath, $reference);

                if ($occurrence !== null) {
                    $occurrences[] = $occurrence;
                }
            }

            if (!is_array($renderMethod->stmts)) {
                return (
                    $symbols === []
                    && $occurrences === []
                        ? null
                        : ['symbols' => $symbols, 'occurrences' => $occurrences]
                );
            }

            foreach ($this->nodeFinder->findInstanceOf($renderMethod->stmts, MethodCall::class) as $call) {
                $methodName = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;

                if ($methodName === 'title' && is_string($renderSymbol) && $renderSymbol !== '') {
                    $title = $this->literalString($call->getArgs()[0]->value ?? null);

                    if ($title !== null) {
                        $symbols[] = $this->symbolPatch($relativePath, $renderSymbol, ['Livewire title: ' . $title]);
                    }

                    continue;
                }

                if ($methodName !== 'layout' && $methodName !== 'extends') {
                    continue;
                }

                $reference = $this->viewReferenceFromNode(
                    $call->getArgs()[0]->value ?? null,
                    $contents,
                    $methodName === 'layout' ? 'livewire-layout' : 'livewire-extends',
                );

                if ($reference === null) {
                    continue;
                }

                $occurrence = $this->layoutOccurrence($context, $viewCatalog, $relativePath, $reference);

                if ($occurrence !== null) {
                    $occurrences[] = $occurrence;
                }
            }
        }

        return $symbols === [] && $occurrences === [] ? null : ['symbols' => $symbols, 'occurrences' => $occurrences];
    }

    /**
     * @param array{
     *   symbolsByName: array<string, string>,
     *   pathsByName: array<string, string>,
     *   symbolsByPath: array<string, list<string>>
     * } $viewCatalog
     * @return list<array{symbols: list<DocumentSymbolPatch>, occurrences: list<DocumentOccurrencePatch>}>
     */
    private function voltPayloads(LaravelContext $context, object $livewireInventory, array $viewCatalog): array
    {
        $payloads = [];

        foreach ($this->bladeFiles($context->projectRoot) as $filePath) {
            $contents = $this->bladeCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $preamble = $this->voltPreambleParser->parse($contents);

            if ($preamble === null) {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);
            $componentContext = $livewireInventory->forDocument($documentPath);

            if ($componentContext === null) {
                continue;
            }

            $symbols = [];
            $occurrences = [];

            foreach ($preamble->propertyMetadata as $name => $documentation) {
                $symbol = $componentContext->propertySymbols[$name] ?? null;

                if (!is_string($symbol) || $symbol === '' || $documentation === []) {
                    continue;
                }

                $symbols[] = $this->symbolPatch($documentPath, $symbol, $documentation);
            }

            foreach ($preamble->methodMetadata as $name => $documentation) {
                $symbol = $componentContext->methodSymbols[$name] ?? null;

                if (!is_string($symbol) || $symbol === '' || $documentation === []) {
                    continue;
                }

                $symbols[] = $this->symbolPatch($documentPath, $symbol, $documentation);
            }

            $resolvedFilePath = realpath($filePath) ?: $filePath;

            foreach ($viewCatalog['symbolsByPath'][$resolvedFilePath] ?? [] as $viewSymbol) {
                if ($preamble->viewMetadata !== []) {
                    $symbols[] = $this->symbolPatch($documentPath, $viewSymbol, $preamble->viewMetadata);
                }
            }

            foreach ($preamble->layoutReferences as $reference) {
                $occurrence = $this->layoutOccurrence($context, $viewCatalog, $documentPath, $reference);

                if ($occurrence !== null) {
                    $occurrences[] = $occurrence;
                }
            }

            if ($symbols !== [] || $occurrences !== []) {
                $payloads[] = ['symbols' => $symbols, 'occurrences' => $occurrences];
            }
        }

        return $payloads;
    }

    private function symbolPatch(
        string $documentPath,
        string $symbol,
        array $documentation,
        ?Document $signatureDocumentation = null,
    ): DocumentSymbolPatch {
        sort($documentation);

        $payload = [
            'symbol' => $symbol,
            'documentation' => array_values(array_unique($documentation)),
        ];

        if ($signatureDocumentation !== null) {
            $payload['signature_documentation'] = $signatureDocumentation;
        }

        return new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation($payload));
    }

    /**
     * @param array{
     *   symbolsByName: array<string, string>,
     *   pathsByName: array<string, string>,
     *   symbolsByPath: array<string, list<string>>
     * } $viewCatalog
     */
    private function layoutOccurrence(
        LaravelContext $context,
        array $viewCatalog,
        string $documentPath,
        BladeLiteralReference $reference,
    ): ?DocumentOccurrencePatch {
        $resolvedPath = $viewCatalog['pathsByName'][$reference->literal] ?? null;
        $symbol = $viewCatalog['symbolsByName'][$reference->literal] ?? null;

        if (!is_string($resolvedPath) || !is_string($symbol)) {
            return null;
        }

        if (!$this->isProjectPath($context, $resolvedPath)) {
            return null;
        }

        return new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
            'range' => $reference->range->toScipRange(),
            'symbol' => $symbol,
            'symbol_roles' => SymbolRole::ReadAccess,
            'syntax_kind' => SyntaxKind::StringLiteralKey,
        ]));
    }

    /**
     * @param list<AttributeGroup> $attributeGroups
     * @return list<string>
     */
    private function propertyDocumentation(array $attributeGroups): array
    {
        $documentation = [];

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null) {
                    continue;
                }

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

                if ($this->matchesName($attributeName, self::MODELABLE_ATTRIBUTE_NAMES)) {
                    $documentation[] = 'Livewire modelable property';
                    continue;
                }

                if ($this->matchesName($attributeName, self::REACTIVE_ATTRIBUTE_NAMES)) {
                    $documentation[] = 'Livewire reactive property';
                }
            }
        }

        return array_values(array_unique($documentation));
    }

    /**
     * @param list<AttributeGroup> $attributeGroups
     * @return list<string>
     */
    private function methodDocumentation(array $attributeGroups): array
    {
        $documentation = [];

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null) {
                    continue;
                }

                if ($this->matchesName($attributeName, self::COMPUTED_ATTRIBUTE_NAMES)) {
                    $documentation[] = 'Livewire computed property';
                    continue;
                }

                if ($this->matchesName($attributeName, self::ON_ATTRIBUTE_NAMES)) {
                    foreach ($this->literalStrings($attribute->args[0]->value ?? null) as $eventName) {
                        $documentation[] = 'Livewire event listener: ' . $eventName;
                    }
                }
            }
        }

        return array_values(array_unique($documentation));
    }

    /**
     * @param list<AttributeGroup> $attributeGroups
     * @return list<string>
     */
    private function titleDocumentation(array $attributeGroups): array
    {
        $documentation = [];

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null || !$this->matchesName($attributeName, self::TITLE_ATTRIBUTE_NAMES)) {
                    continue;
                }

                $title = $this->literalString($attribute->args[0]->value ?? null);

                if ($title !== null) {
                    $documentation[] = 'Livewire title: ' . $title;
                }
            }
        }

        return array_values(array_unique($documentation));
    }

    /**
     * @param list<AttributeGroup> $attributeGroups
     * @return list<BladeLiteralReference>
     */
    private function attributeLayoutReferences(array $attributeGroups, string $contents): array
    {
        $references = [];

        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributeName = $this->normalizedName($attribute->name);

                if ($attributeName === null || !$this->matchesName($attributeName, self::LAYOUT_ATTRIBUTE_NAMES)) {
                    continue;
                }

                $reference = $this->viewReferenceFromNode(
                    $attribute->args[0]->value ?? null,
                    $contents,
                    'livewire-layout',
                );

                if ($reference !== null) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    private function viewReferenceFromNode(?Node $node, string $contents, string $directive): ?BladeLiteralReference
    {
        if (!$node instanceof String_ || $node->value === '') {
            return null;
        }

        return new BladeLiteralReference(
            domain: 'view',
            directive: $directive,
            literal: $node->value,
            range: $this->nodeRange($node, $contents),
        );
    }

    private function nodeRange(Node $node, string $contents): SourceRange
    {
        $startOffset = $node->getStartFilePos();
        $endOffset = $node->getEndFilePos();

        return SourceRange::fromOffsets($contents, $startOffset, $endOffset + 1);
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

        if (!$node instanceof \PhpParser\Node\Expr\Array_) {
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

    private function resolvedClassName(Class_ $class): ?string
    {
        $resolved = $class->namespacedName ?? null;

        return $resolved instanceof Name ? ltrim($resolved->toString(), '\\') : null;
    }

    private function normalizedName(Node|string|null $name): ?string
    {
        if (is_string($name) && $name !== '') {
            return ltrim($name, '\\');
        }

        if ($name instanceof Name) {
            return ltrim($name->toString(), '\\');
        }

        return null;
    }

    /**
     * @param list<string> $candidates
     */
    private function matchesName(string $name, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (strtolower(ltrim($candidate, '\\')) === strtolower(ltrim($name, '\\'))) {
                return true;
            }
        }

        return false;
    }

    private function isLivewireComponent(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable) {
            return false;
        }

        return $reflection->isSubclassOf('Livewire\\Component');
    }

    /**
     * @return array{
     *   symbolsByName: array<string, string>,
     *   pathsByName: array<string, string>,
     *   symbolsByPath: array<string, list<string>>
     * }
     */
    private function viewCatalog(LaravelContext $context): array
    {
        $finder = $this->viewFinder($context);

        if ($finder === null) {
            return ['symbolsByName' => [], 'pathsByName' => [], 'symbolsByPath' => []];
        }

        $candidatePathsByName = [];

        foreach ($this->viewRoots($context, $finder) as $root) {
            foreach ($this->viewFiles($root['path']) as $filePath) {
                $name = $this->viewName($root['path'], $filePath, $root['namespace']);
                $candidatePathsByName[$name][$filePath] = true;
            }
        }

        if ($candidatePathsByName === []) {
            return ['symbolsByName' => [], 'pathsByName' => [], 'symbolsByPath' => []];
        }

        ksort($candidatePathsByName);
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $symbolsByName = [];
        $pathsByName = [];
        $symbolsByPath = [];
        $resolvedViewPathCache = [];

        foreach ($candidatePathsByName as $name => $paths) {
            if (count($paths) !== 1) {
                continue;
            }

            $resolvedPath = $resolvedViewPathCache[$name] ??= $this->resolveViewPath($finder, $name);
            $candidatePath = array_keys($paths)[0];

            if ($resolvedPath === null || $resolvedPath !== $candidatePath) {
                continue;
            }

            $symbol = $normalizer->view($name);
            $symbolsByName[$name] = $symbol;
            $pathsByName[$name] = $resolvedPath;
            $symbolsByPath[$resolvedPath][] = $symbol;
        }

        ksort($symbolsByName);
        ksort($pathsByName);
        ksort($symbolsByPath);

        foreach ($symbolsByPath as &$symbols) {
            sort($symbols);
        }

        unset($symbols);

        return [
            'symbolsByName' => $symbolsByName,
            'pathsByName' => $pathsByName,
            'symbolsByPath' => $symbolsByPath,
        ];
    }

    private function viewFinder(LaravelContext $context): ?object
    {
        if (!is_object($context->application) || !method_exists($context->application, 'make')) {
            return null;
        }

        try {
            $factory = $context->application->make('view');
        } catch (Throwable) {
            return null;
        }

        if (!is_object($factory) || !method_exists($factory, 'getFinder')) {
            return null;
        }

        $finder = $factory->getFinder();

        return is_object($finder) ? $finder : null;
    }

    /**
     * @return list<array{namespace: ?string, path: string}>
     */
    private function viewRoots(LaravelContext $context, object $finder): array
    {
        $roots = [];

        if (method_exists($finder, 'getPaths')) {
            foreach ($finder->getPaths() as $path) {
                if (is_string($path) && $this->isLocalProjectPath($context, $path) && is_dir($path)) {
                    $roots[] = ['namespace' => null, 'path' => $path];
                }
            }
        }

        if (method_exists($finder, 'getHints')) {
            foreach ($finder->getHints() as $namespace => $paths) {
                if (!is_string($namespace) || !is_array($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    if (is_string($path) && $this->isLocalProjectPath($context, $path) && is_dir($path)) {
                        $roots[] = ['namespace' => $namespace, 'path' => $path];
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function viewFiles(string $root): array
    {
        return $this->bladeCache->viewFiles($root);
    }

    private function viewName(string $root, string $filePath, ?string $namespace): string
    {
        $relativePath = substr($filePath, strlen($root) + 1);
        $name = str_replace(DIRECTORY_SEPARATOR, '.', $relativePath);

        foreach (['.blade.php', '.php', '.html'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        return $namespace === null ? $name : $namespace . '::' . $name;
    }

    private function resolveViewPath(object $finder, string $name): ?string
    {
        if (!method_exists($finder, 'find')) {
            return null;
        }

        try {
            $path = $finder->find($name);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? (realpath($path) ?: $path) : null;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        return $this->analysisCache->phpFilesInRoots([$root]);
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $projectRoot): array
    {
        return $this->bladeCache->bladeFiles($projectRoot);
    }

    private function isProjectPath(LaravelContext $context, string $path): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedRoot = realpath($context->projectRoot) ?: $context->projectRoot;
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, $rootPrefix);
    }

    private function isLocalProjectPath(LaravelContext $context, string $path): bool
    {
        return (
            $this->isProjectPath($context, $path)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)
            && !str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
        );
    }
}

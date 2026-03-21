<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionException;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;
use Throwable;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function ltrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

final class ProjectFallbackSymbolResolver
{
    /** @var array<class-string, ?ReflectionClass> */
    private static array $reflectionCache = [];

    /** @var array<string, ?list<Node>> */
    private static array $astCache = [];

    /** @var array<string, ?ClassLike> */
    private static array $classLikeCache = [];

    /** @var array<string, ?string> */
    private static array $fileContentsCache = [];

    public static function reset(): void
    {
        self::$reflectionCache = [];
        self::$astCache = [];
        self::$classLikeCache = [];
        self::$fileContentsCache = [];
    }

    private Parser $parser;

    private NodeFinder $nodeFinder;

    public function __construct(
        private readonly BaselineClassSymbolResolver $classResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodResolver = new BaselineMethodSymbolResolver(),
        private readonly BaselinePropertySymbolResolver $propertyResolver = new BaselinePropertySymbolResolver(),
    ) {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function resolveClass(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $className,
    ): ?ResolvedProjectSymbol {
        $reflection = $this->reflection($className);

        if ($reflection === null) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);

        if (!$this->isProjectDocument($documentPath)) {
            return null;
        }

        try {
            $line = $reflection->getStartLine();
        } catch (Throwable) {
            return null;
        }

        $symbol = $this->classResolver->resolve($context->baselineIndex, $documentPath, $className, $line);

        if (is_string($symbol) && $symbol !== '') {
            return new ResolvedProjectSymbol($symbol, $documentPath);
        }

        $range = $this->classLikeNameRange($filePath, $className);

        if ($range === null) {
            return null;
        }

        $symbol = $normalizer->domainSymbol('php-class', $className);

        return new ResolvedProjectSymbol(
            symbol: $symbol,
            documentPath: $documentPath,
            symbolPatch: new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $this->displayName($className),
                'kind' => Kind::PBClass,
            ])),
            definitionPatch: new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::Identifier,
            ])),
        );
    }

    public function resolveMethod(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $className,
        string $methodName,
    ): ?ResolvedProjectSymbol {
        $reflection = $this->reflection($className);

        if ($reflection === null || !$reflection->hasMethod($methodName)) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        try {
            $line = $reflection->getMethod($methodName)->getStartLine();
        } catch (Throwable) {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);

        if (!$this->isProjectDocument($documentPath)) {
            return null;
        }

        $symbol = $this->methodResolver->resolve($context->baselineIndex, $documentPath, $methodName, $line);

        if (is_string($symbol) && $symbol !== '') {
            return new ResolvedProjectSymbol($symbol, $documentPath);
        }

        $range = $this->methodNameRange($filePath, $className, $methodName);

        if ($range === null) {
            return null;
        }

        $symbol = $normalizer->domainSymbol('php-method', $className . '::' . $methodName);

        return new ResolvedProjectSymbol(
            symbol: $symbol,
            documentPath: $documentPath,
            symbolPatch: new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $methodName,
                'kind' => Kind::Method,
            ])),
            definitionPatch: new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::Identifier,
            ])),
        );
    }

    public function resolveProperty(
        LaravelContext $context,
        SyntheticSymbolNormalizer $normalizer,
        string $className,
        string $propertyName,
    ): ?ResolvedProjectSymbol {
        $reflection = $this->reflection($className);

        if ($reflection === null || !$reflection->hasProperty($propertyName)) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);

        if (!$this->isProjectDocument($documentPath)) {
            return null;
        }

        $symbol = $this->propertyResolver->resolve($context->baselineIndex, $documentPath, $className, $propertyName);

        if (is_string($symbol) && $symbol !== '') {
            return new ResolvedProjectSymbol($symbol, $documentPath);
        }

        $range = $this->propertyNameRange($filePath, $className, $propertyName);

        if ($range === null) {
            return null;
        }

        $symbol = $normalizer->domainSymbol('php-property', $className . '::$' . $propertyName);

        return new ResolvedProjectSymbol(
            symbol: $symbol,
            documentPath: $documentPath,
            symbolPatch: new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $propertyName,
                'kind' => Kind::Property,
            ])),
            definitionPatch: new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                'range' => $range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::Identifier,
            ])),
        );
    }

    private function reflection(string $className): ?ReflectionClass
    {
        if (array_key_exists($className, self::$reflectionCache)) {
            return self::$reflectionCache[$className];
        }

        try {
            return self::$reflectionCache[$className] = new ReflectionClass($className);
        } catch (ReflectionException) {
            self::$reflectionCache[$className] = null;

            return null;
        }
    }

    private function isProjectDocument(string $documentPath): bool
    {
        return (
            str_starts_with($documentPath, 'app/')
            || str_starts_with($documentPath, 'routes/')
            || str_starts_with($documentPath, 'bootstrap/')
        );
    }

    private function displayName(string $className): string
    {
        if (!str_contains($className, '\\')) {
            return $className;
        }

        return substr($className, (int) strrpos($className, '\\') + 1);
    }

    private function classLikeNameRange(string $filePath, string $className): ?SourceRange
    {
        $classLike = $this->classLikeNode($filePath, $className);

        if (!$classLike instanceof ClassLike || $classLike->name === null) {
            return null;
        }

        return $this->nodeRange($classLike->name, $this->fileContents($filePath));
    }

    private function methodNameRange(string $filePath, string $className, string $methodName): ?SourceRange
    {
        $classLike = $this->classLikeNode($filePath, $className);

        if (!$classLike instanceof ClassLike) {
            return null;
        }

        foreach ($classLike->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== strtolower($methodName)) {
                continue;
            }

            return $this->nodeRange($method->name, $this->fileContents($filePath));
        }

        return null;
    }

    private function propertyNameRange(string $filePath, string $className, string $propertyName): ?SourceRange
    {
        $classLike = $this->classLikeNode($filePath, $className);

        if (!$classLike instanceof ClassLike) {
            return null;
        }

        foreach ($classLike->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== $propertyName) {
                    continue;
                }

                return $this->nodeRange($prop->name, $this->fileContents($filePath));
            }
        }

        return null;
    }

    private function classLikeNode(string $filePath, string $className): ?ClassLike
    {
        $cacheKey = $filePath . "\x1F" . $className;

        if (array_key_exists($cacheKey, self::$classLikeCache)) {
            return self::$classLikeCache[$cacheKey];
        }

        $ast = $this->ast($filePath);

        if ($ast === null) {
            self::$classLikeCache[$cacheKey] = null;

            return null;
        }

        foreach ($this->nodeFinder->find(
            $ast,
            static fn(Node $node): bool => $node instanceof ClassLike,
        ) as $classLike) {
            if (!$classLike instanceof ClassLike) {
                continue;
            }

            $namespacedName = $classLike->namespacedName ?? null;
            $resolvedName = $namespacedName instanceof Name ? ltrim($namespacedName->toString(), '\\') : null;

            if ($resolvedName === $className) {
                self::$classLikeCache[$cacheKey] = $classLike;

                return $classLike;
            }
        }

        self::$classLikeCache[$cacheKey] = null;

        return null;
    }

    /**
     * @return ?list<Node>
     */
    private function ast(string $filePath): ?array
    {
        if (array_key_exists($filePath, self::$astCache)) {
            return self::$astCache[$filePath];
        }

        $contents = $this->fileContents($filePath);

        if ($contents === null || $contents === '') {
            self::$astCache[$filePath] = null;

            return null;
        }

        try {
            $ast = $this->parser->parse($contents);
        } catch (Error) {
            self::$astCache[$filePath] = null;

            return null;
        }

        if (!is_array($ast)) {
            self::$astCache[$filePath] = null;

            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));

        return self::$astCache[$filePath] = $traverser->traverse($ast);
    }

    private function fileContents(string $filePath): ?string
    {
        if (array_key_exists($filePath, self::$fileContentsCache)) {
            return self::$fileContentsCache[$filePath];
        }

        $contents = file_get_contents($filePath);

        self::$fileContentsCache[$filePath] = is_string($contents) ? $contents : null;

        return self::$fileContentsCache[$filePath];
    }

    private function nodeRange(Node $node, ?string $contents): ?SourceRange
    {
        if ($contents === null) {
            return null;
        }

        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            return null;
        }

        return SourceRange::fromOffsets($contents, $start, $end + 1);
    }
}

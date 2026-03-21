<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Metadata;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineConstantSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselineMethodSymbolResolver;
use Bnomei\ScipLaravel\Support\BaselinePropertySymbolResolver;
use Bnomei\ScipLaravel\Support\PhpClassConstantFetchFinder;
use Bnomei\ScipLaravel\Support\PhpDeclaredClass;
use Bnomei\ScipLaravel\Support\PhpDeclaredClassFinder;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Scip\Document;
use Scip\Occurrence;
use Scip\Relationship;
use Scip\SymbolInformation;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_merge;
use function array_values;
use function in_array;
use function is_dir;
use function is_string;
use function ksort;
use function ltrim;
use function sort;

final class CanonicalPhpMetadataEnricher implements Enricher
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_APP_SEGMENTS = [
        'app/Console',
        'app/Contracts',
        'app/Events',
        'app/Http/Controllers',
        'app/Http/Requests',
        'app/Http/Resources',
        'app/Jobs',
        'app/Listeners',
        'app/Livewire',
        'app/Models',
        'app/Notifications',
        'app/Policies',
        'app/Providers',
        'app/Services',
        'app/Support',
        'app/View/Components',
        'tests',
    ];

    public function __construct(
        private readonly PhpDeclaredClassFinder $classFinder = new PhpDeclaredClassFinder(),
        private readonly PhpClassConstantFetchFinder $constantFetchFinder = new PhpClassConstantFetchFinder(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly BaselineMethodSymbolResolver $methodSymbolResolver = new BaselineMethodSymbolResolver(),
        private readonly BaselinePropertySymbolResolver $propertySymbolResolver = new BaselinePropertySymbolResolver(),
        private readonly BaselineConstantSymbolResolver $constantSymbolResolver = new BaselineConstantSymbolResolver(),
    ) {}

    public function feature(): string
    {
        return 'routes';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $declaredClasses = $this->classFinder->findInRoots($this->roots($context));

        if ($declaredClasses === []) {
            return IndexPatch::empty();
        }

        $declarationsByClass = [];

        foreach ($declaredClasses as $declaration) {
            $declarationsByClass[$declaration->className] = $declaration;
        }

        ksort($declarationsByClass);
        $classSymbolsByName = $this->classSymbolsByName($context, $declarationsByClass);
        $symbols = [];
        $occurrences = [];

        foreach ($declarationsByClass as $className => $declaration) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (ReflectionException) {
                continue;
            }

            $documentPath = $context->relativeProjectPath($declaration->filePath);
            $classSymbol = $classSymbolsByName[$className] ?? null;

            if (!is_string($classSymbol) || $classSymbol === '') {
                continue;
            }

            $classDocumentation = $context->surveyor->publicApiSummaryDocumentation($className);
            $serializationDocumentation = $this->serializationDocumentation($context, $className);

            $relationships = $this->classRelationships($context, $declarationsByClass, $classSymbolsByName, $className);

            if ($classDocumentation !== [] || $serializationDocumentation !== [] || $relationships !== []) {
                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $classSymbol,
                    'documentation' => $this->normalizedDocumentation([
                        ...$classDocumentation,
                        ...$serializationDocumentation,
                    ]),
                    'relationships' => $relationships,
                ]));
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (
                    $method->getDeclaringClass()->getName() !== $className
                    || str_starts_with($method->getName(), '__')
                ) {
                    continue;
                }

                $methodSymbol = $this->methodSymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    $method->getName(),
                    $method->getStartLine(),
                );

                if (!is_string($methodSymbol) || $methodSymbol === '') {
                    continue;
                }

                $metadata = $context->surveyor->methodMetadataPayload($className, $method->getName());
                $payload = ['symbol' => $methodSymbol, 'documentation' => $metadata['documentation']];
                $signature = $context->surveyor->methodSignatureDocumentation($className, $method->getName());

                if ($signature instanceof Document) {
                    $payload['signature_documentation'] = $signature;
                }

                if ($metadata['documentation'] !== [] || $signature instanceof Document) {
                    $symbols[] = new DocumentSymbolPatch(
                        documentPath: $documentPath,
                        symbol: new SymbolInformation($payload),
                    );
                }
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $propertySymbol = $this->propertySymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    $className,
                    $property->getName(),
                );

                if (!is_string($propertySymbol) || $propertySymbol === '') {
                    continue;
                }

                $metadata = $context->surveyor->propertyMetadataPayload($className, $property->getName());
                $payload = ['symbol' => $propertySymbol, 'documentation' => $metadata['documentation']];
                $signature = $context->surveyor->propertySignatureDocumentation($className, $property->getName());

                if ($signature instanceof Document) {
                    $payload['signature_documentation'] = $signature;
                }

                if ($metadata['documentation'] !== [] || $signature instanceof Document) {
                    $symbols[] = new DocumentSymbolPatch(
                        documentPath: $documentPath,
                        symbol: new SymbolInformation($payload),
                    );
                }
            }

            foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $constantSymbol = $this->constantSymbolResolver->resolve(
                    $context->baselineIndex,
                    $documentPath,
                    $className,
                    $constant->getName(),
                );

                if (!is_string($constantSymbol) || $constantSymbol === '') {
                    continue;
                }

                $metadata = $context->surveyor->constantMetadataPayload($className, $constant->getName());
                $payload = ['symbol' => $constantSymbol, 'documentation' => $metadata['documentation']];
                $signature = $context->surveyor->constantSignatureDocumentation($className, $constant->getName());

                if ($signature instanceof Document) {
                    $payload['signature_documentation'] = $signature;
                }

                if ($metadata['documentation'] !== [] || $signature instanceof Document) {
                    $symbols[] = new DocumentSymbolPatch(
                        documentPath: $documentPath,
                        symbol: new SymbolInformation($payload),
                    );
                }
            }
        }

        foreach ($this->constantFetchFinder->find($context->projectRoot) as $fetch) {
            $declaration = $declarationsByClass[ltrim($fetch->className, '\\')] ?? null;

            if (!$declaration instanceof PhpDeclaredClass) {
                continue;
            }

            $targetPath = $context->relativeProjectPath($declaration->filePath);
            $symbol = $this->constantSymbolResolver->resolve(
                $context->baselineIndex,
                $targetPath,
                $declaration->className,
                $fetch->constantName,
            );

            if (!is_string($symbol) || $symbol === '') {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($fetch->filePath),
                occurrence: new Occurrence([
                    'range' => $fetch->range->toScipRange(),
                    'symbol' => $symbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]),
            );
        }

        return $symbols === [] && $occurrences === []
            ? IndexPatch::empty()
            : new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    /**
     * @return list<string>
     */
    private function roots(LaravelContext $context): array
    {
        $roots = [];

        foreach (self::SUPPORTED_APP_SEGMENTS as $segment) {
            $root = $context->projectPath($segment);

            if (is_dir($root)) {
                $roots[] = $root;
            }
        }

        sort($roots);

        return $roots;
    }

    /**
     * @param array<string, PhpDeclaredClass> $declarationsByClass
     * @param array<string, string> $classSymbolsByName
     * @return list<Relationship>
     */
    private function classRelationships(
        LaravelContext $context,
        array $declarationsByClass,
        array $classSymbolsByName,
        string $className,
    ): array {
        $relationships = [];
        $targets = $context->surveyor->classRelationships($className);

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            $reflection = null;
        }

        if (
            $reflection instanceof ReflectionClass
            && $reflection->isInterface()
            && ($targets['extends'] ?? []) === []
        ) {
            $targets['extends'] = $reflection->getInterfaceNames();
            sort($targets['extends']);
        }

        foreach (['extends', 'implements'] as $kind) {
            foreach ($targets[$kind] ?? [] as $targetClass) {
                $declaration = $declarationsByClass[$targetClass] ?? null;

                if (!$declaration instanceof PhpDeclaredClass) {
                    continue;
                }

                $symbol = $classSymbolsByName[$declaration->className] ?? null;

                if (!is_string($symbol) || $symbol === '') {
                    continue;
                }

                $payload = [
                    'symbol' => $symbol,
                    'is_reference' => true,
                ];

                if ($kind === 'implements') {
                    $payload['is_implementation'] = true;
                } else {
                    $payload['is_type_definition'] = true;
                }

                $relationships[$kind . ':' . $symbol] = new Relationship($payload);
            }
        }

        ksort($relationships);

        return array_values($relationships);
    }

    /**
     * @param array<string, PhpDeclaredClass> $declarationsByClass
     * @return array<string, string>
     */
    private function classSymbolsByName(LaravelContext $context, array $declarationsByClass): array
    {
        $symbols = [];

        foreach ($declarationsByClass as $className => $declaration) {
            $symbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $context->relativeProjectPath($declaration->filePath),
                $className,
                $declaration->lineNumber,
            );

            if (is_string($symbol) && $symbol !== '') {
                $symbols[$className] = $symbol;
            }
        }

        ksort($symbols);

        return $symbols;
    }

    /**
     * @return list<string>
     */
    private function serializationDocumentation(LaravelContext $context, string $className): array
    {
        $class = $context->surveyor->class($className);

        if ($class === null) {
            return [];
        }

        $documentation = [];

        if ($class->isArrayable()) {
            $signature = $context->surveyor->methodSignatureText($className, 'toArray');
            $documentation[] = $signature !== null ? 'Arrayable contract: ' . $signature : 'Arrayable contract';
        }

        if ($class->isJsonSerializable()) {
            $signature = $context->surveyor->methodSignatureText($className, 'jsonSerialize');
            $documentation[] = $signature !== null
                ? 'JsonSerializable contract: ' . $signature
                : 'JsonSerializable contract';
        }

        return $documentation;
    }

    /**
     * @param list<string> $documentation
     * @return list<string>
     */
    private function normalizedDocumentation(array $documentation): array
    {
        $documentation = array_values(array_filter(
            $documentation,
            static fn(mixed $line): bool => is_string($line) && $line !== '',
        ));
        $documentation = array_values(array_unique($documentation));
        sort($documentation);

        return $documentation;
    }
}

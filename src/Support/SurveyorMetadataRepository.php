<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Analyzed\ConstantResult;
use Laravel\Surveyor\Analyzed\MethodResult;
use Laravel\Surveyor\Analyzed\PropertyResult;
use Laravel\Surveyor\Analyzer\Analyzer;
use ReflectionClass;
use ReflectionProperty;
use Scip\Document;
use Throwable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function sort;

final class SurveyorMetadataRepository
{
    /**
     * @var array<string, ?ClassResult>
     */
    private array $classResults = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $formattedCache = [];

    private static ?ReflectionProperty $classResultConstantsProperty = null;

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
        private readonly ValidationRuleFormatter $validationFormatter = new ValidationRuleFormatter(),
    ) {}

    public function class(string $className): ?ClassResult
    {
        if (array_key_exists($className, $this->classResults)) {
            return $this->classResults[$className];
        }

        try {
            $result = $this->analyzer->analyzeClass($className)->result();
        } catch (Throwable) {
            $result = null;
        }

        return $this->classResults[$className] = $result instanceof ClassResult ? $result : null;
    }

    public function method(string $className, string $methodName): ?MethodResult
    {
        $class = $this->class($className);

        if (!$class instanceof ClassResult || !$class->hasMethod($methodName)) {
            return null;
        }

        return $class->getMethod($methodName);
    }

    public function property(string $className, string $propertyName): ?PropertyResult
    {
        $class = $this->class($className);

        if (!$class instanceof ClassResult || !$class->hasProperty($propertyName)) {
            return null;
        }

        return $class->getProperty($propertyName);
    }

    public function constant(string $className, string $constantName): ?ConstantResult
    {
        $class = $this->class($className);

        if (!$class instanceof ClassResult || !$class->hasConstant($constantName)) {
            return null;
        }

        return $class->getConstant($constantName);
    }

    /**
     * @return array{extends: list<string>, implements: list<string>}
     */
    public function classRelationships(string $className): array
    {
        /** @var array{extends: list<string>, implements: list<string>} $relationships */
        $relationships = $this->memoize('class-relationships', $className, function () use ($className): array {
            $class = $this->class($className);

            if (!$class instanceof ClassResult) {
                return ['extends' => [], 'implements' => []];
            }

            return [
                'extends' => $this->normalizeClassNameList($class->extends()),
                'implements' => $this->normalizeClassNameList($class->implements()),
            ];
        });

        return $relationships;
    }

    /**
     * @return list<string>
     */
    public function classRelationshipDocumentation(string $className, int $limit = 6): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('class-relationship-docs', $className . ':' . $limit, function () use (
            $className,
            $limit,
        ): array {
            $relationships = $this->classRelationships($className);
            $documentation = [];

            if ($relationships['extends'] !== []) {
                $documentation[] = 'Surveyor extends: ' . $this->boundedList($relationships['extends'], $limit);
            }

            if ($relationships['implements'] !== []) {
                $documentation[] = 'Surveyor implements: ' . $this->boundedList($relationships['implements'], $limit);
            }

            return array_values($documentation);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function publicApiSummaryDocumentation(string $className, int $limit = 6): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('public-api-summary', $className . ':' . $limit, function () use (
            $className,
            $limit,
        ): array {
            $class = $this->class($className);

            if (!$class instanceof ClassResult) {
                return [];
            }

            $documentation = $this->classRelationshipDocumentation($className, $limit);
            $methods = $this->classMethodNames($class);
            $properties = $this->classPropertyNames($class);
            $constants = $this->classConstantNames($className, $class);

            if ($methods !== []) {
                $documentation[] = 'Surveyor public methods: ' . $this->boundedList($methods, $limit);
            }

            if ($properties !== []) {
                $documentation[] = 'Surveyor public properties: ' . $this->boundedList($properties, $limit);
            }

            if ($constants !== []) {
                $documentation[] = 'Surveyor constants: ' . $this->boundedList($constants, $limit);
            }

            return array_values($documentation);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function methodDocumentation(string $className, string $methodName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('method-documentation', $className . ':' . $methodName, function () use (
            $className,
            $methodName,
        ): array {
            $method = $this->method($className, $methodName);

            if (!$method instanceof MethodResult) {
                return [];
            }

            return $this->methodDocumentationFromMethod($method);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function methodParameterContractDocumentation(string $className, string $methodName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('method-parameter-contract', $className . ':' . $methodName, function () use (
            $className,
            $methodName,
        ): array {
            $method = $this->method($className, $methodName);

            if (!$method instanceof MethodResult) {
                return [];
            }

            return $this->methodParameterContractDocumentationFromMethod($method);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function methodReturnContractDocumentation(string $className, string $methodName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('method-return-contract', $className . ':' . $methodName, function () use (
            $className,
            $methodName,
        ): array {
            $method = $this->method($className, $methodName);

            if (!$method instanceof MethodResult) {
                return [];
            }

            return $this->methodReturnContractDocumentationFromMethod($method);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function methodReturnVariantDocumentation(string $className, string $methodName, int $limit = 4): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize(
            'method-return-variants',
            $className . ':' . $methodName . ':' . $limit,
            function () use ($className, $methodName, $limit): array {
                $method = $this->method($className, $methodName);

                if (!$method instanceof MethodResult) {
                    return [];
                }

                return $this->methodReturnVariantDocumentationFromMethod($method, $limit);
            },
        );

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function propertyDocumentation(string $className, string $propertyName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('property-documentation', $className . ':' . $propertyName, function () use (
            $className,
            $propertyName,
        ): array {
            $property = $this->property($className, $propertyName);

            if (!$property instanceof PropertyResult) {
                return [];
            }

            return $this->propertyDocumentationFromProperty($property);
        });

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function propertyKindDocumentation(string $className, string $propertyName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize(
            'property-kind-documentation',
            $className . ':' . $propertyName,
            function () use ($className, $propertyName): array {
                $property = $this->property($className, $propertyName);

                if (!$property instanceof PropertyResult) {
                    return [];
                }

                return $this->propertyKindDocumentationFromProperty($property);
            },
        );

        return $documentation;
    }

    /**
     * @return list<string>
     */
    public function constantDocumentation(string $className, string $constantName): array
    {
        /** @var list<string> $documentation */
        $documentation = $this->memoize('constant-documentation', $className . ':' . $constantName, function () use (
            $className,
            $constantName,
        ): array {
            $constant = $this->constant($className, $constantName);

            if (!$constant instanceof ConstantResult) {
                return [];
            }

            return $this->constantDocumentationFromConstant($constant);
        });

        return $documentation;
    }

    /**
     * @return array{documentation: list<string>, signature: ?string}
     */
    public function methodMetadataPayload(string $className, string $methodName): array
    {
        /** @var array{documentation: list<string>, signature: ?string} $payload */
        $payload = $this->memoize('method-metadata-payload', $className . ':' . $methodName, function () use (
            $className,
            $methodName,
        ): array {
            $method = $this->method($className, $methodName);

            if (!$method instanceof MethodResult) {
                return ['documentation' => [], 'signature' => null];
            }

            return [
                'documentation' => $this->normalizeStringList([
                    ...$this->methodDocumentationFromMethod($method),
                    ...$this->methodParameterContractDocumentationFromMethod($method),
                    ...$this->methodReturnContractDocumentationFromMethod($method),
                    ...$this->methodReturnVariantDocumentationFromMethod($method),
                ]),
                'signature' => $this->methodSignatureFromMethod($methodName, $method),
            ];
        });

        return $payload;
    }

    /**
     * @return array{documentation: list<string>, signature: ?string}
     */
    public function propertyMetadataPayload(string $className, string $propertyName): array
    {
        /** @var array{documentation: list<string>, signature: ?string} $payload */
        $payload = $this->memoize('property-metadata-payload', $className . ':' . $propertyName, function () use (
            $className,
            $propertyName,
        ): array {
            $property = $this->property($className, $propertyName);

            if (!$property instanceof PropertyResult) {
                return ['documentation' => [], 'signature' => null];
            }

            return [
                'documentation' => $this->normalizeStringList([
                    ...$this->propertyDocumentationFromProperty($property),
                    ...$this->propertyKindDocumentationFromProperty($property),
                ]),
                'signature' => $this->propertySignatureFromProperty($propertyName, $property),
            ];
        });

        return $payload;
    }

    /**
     * @return array{documentation: list<string>, signature: ?string}
     */
    public function constantMetadataPayload(string $className, string $constantName): array
    {
        /** @var array{documentation: list<string>, signature: ?string} $payload */
        $payload = $this->memoize('constant-metadata-payload', $className . ':' . $constantName, function () use (
            $className,
            $constantName,
        ): array {
            $constant = $this->constant($className, $constantName);

            if (!$constant instanceof ConstantResult) {
                return ['documentation' => [], 'signature' => null];
            }

            return [
                'documentation' => $this->constantDocumentationFromConstant($constant),
                'signature' => $this->constantSignatureFromConstant($constantName, $constant),
            ];
        });

        return $payload;
    }

    public function methodSignatureText(string $className, string $methodName): ?string
    {
        $signature = $this->memoize(
            'method-signature',
            $className . ':' . $methodName,
            fn(): ?string => $this->methodSignature($className, $methodName),
        );

        return is_string($signature) && $signature !== '' ? $signature : null;
    }

    public function propertySignatureText(string $className, string $propertyName): ?string
    {
        $signature = $this->memoize(
            'property-signature',
            $className . ':' . $propertyName,
            fn(): ?string => $this->propertySignature($className, $propertyName),
        );

        return is_string($signature) && $signature !== '' ? $signature : null;
    }

    public function constantSignatureText(string $className, string $constantName): ?string
    {
        $signature = $this->memoize(
            'constant-signature',
            $className . ':' . $constantName,
            fn(): ?string => $this->constantSignature($className, $constantName),
        );

        return is_string($signature) && $signature !== '' ? $signature : null;
    }

    public function methodSignatureDocumentation(string $className, string $methodName): ?Document
    {
        $signature = $this->memoize(
            'method-signature-documentation',
            $className . ':' . $methodName,
            fn(): ?Document => $this->signatureDocument($this->methodSignatureText($className, $methodName)),
        );

        return $signature instanceof Document ? $signature : null;
    }

    public function propertySignatureDocumentation(string $className, string $propertyName): ?Document
    {
        $signature = $this->memoize(
            'property-signature-documentation',
            $className . ':' . $propertyName,
            fn(): ?Document => $this->signatureDocument($this->propertySignatureText($className, $propertyName)),
        );

        return $signature instanceof Document ? $signature : null;
    }

    public function constantSignatureDocumentation(string $className, string $constantName): ?Document
    {
        $signature = $this->memoize(
            'constant-signature-documentation',
            $className . ':' . $constantName,
            fn(): ?Document => $this->signatureDocument($this->constantSignatureText($className, $constantName)),
        );

        return $signature instanceof Document ? $signature : null;
    }

    private function methodSignature(string $className, string $methodName): ?string
    {
        $method = $this->method($className, $methodName);

        if (!$method instanceof MethodResult) {
            return null;
        }

        return $this->methodSignatureFromMethod($methodName, $method);
    }

    private function propertySignature(string $className, string $propertyName): ?string
    {
        $property = $this->property($className, $propertyName);

        if (!$property instanceof PropertyResult) {
            return null;
        }

        return $this->propertySignatureFromProperty($propertyName, $property);
    }

    private function constantSignature(string $className, string $constantName): ?string
    {
        $constant = $this->constant($className, $constantName);

        if (!$constant instanceof ConstantResult) {
            return null;
        }

        return $this->constantSignatureFromConstant($constantName, $constant);
    }

    /**
     * @return list<string>
     */
    private function methodDocumentationFromMethod(MethodResult $method): array
    {
        $documentation = [];
        $returnType = $this->formatMethodReturnType($method);

        if ($returnType !== null) {
            $documentation[] = 'Surveyor return type: ' . $returnType;
        }

        if ($method->validationRules() !== []) {
            $documentation[] =
                'Surveyor validation: ' . $this->validationFormatter->formatSurveyorRuleMap($method->validationRules());
        }

        return array_values($documentation);
    }

    /**
     * @return list<string>
     */
    private function methodParameterContractDocumentationFromMethod(MethodResult $method): array
    {
        $parameters = $method->parameters();
        ksort($parameters);
        $parts = [];

        foreach ($parameters as $name => $type) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $formatted = $this->formatSurveyorType($type);

            if ($formatted === null) {
                continue;
            }

            $parts[] = $name . ': ' . $formatted;
        }

        return $parts === [] ? [] : ['Surveyor parameters: ' . implode(', ', $parts)];
    }

    /**
     * @return list<string>
     */
    private function methodReturnContractDocumentationFromMethod(MethodResult $method): array
    {
        $returnType = $this->formatMethodReturnType($method);

        return $returnType !== null ? ['Surveyor return contract: ' . $returnType] : [];
    }

    /**
     * @return list<string>
     */
    private function methodReturnVariantDocumentationFromMethod(MethodResult $method, int $limit = 4): array
    {
        $variantsByLine = [];

        foreach ($method->returnTypes() as $variant) {
            $lineNumber = $variant['lineNumber'] ?? null;
            $type = $variant['type'] ?? null;

            if (!is_int($lineNumber) || $lineNumber <= 0 || !is_object($type)) {
                continue;
            }

            $formatted = $this->formatSurveyorType($type);

            if ($formatted === null) {
                continue;
            }

            $variantsByLine[$lineNumber][$formatted] = true;
        }

        if ($variantsByLine === []) {
            return [];
        }

        ksort($variantsByLine);
        $lines = [];

        foreach ($variantsByLine as $lineNumber => $types) {
            $typeNames = array_keys($types);
            sort($typeNames);
            $lines[] = 'line ' . $lineNumber . ' => ' . implode('|', $typeNames);
        }

        $visible = array_slice($lines, 0, max(1, $limit));

        if (count($lines) > count($visible)) {
            $visible[] = '+' . (count($lines) - count($visible)) . ' more';
        }

        return ['Surveyor return variants: ' . implode('; ', $visible)];
    }

    /**
     * @return list<string>
     */
    private function propertyDocumentationFromProperty(PropertyResult $property): array
    {
        if ($property->type === null) {
            return [];
        }

        $formatted = $this->formatSurveyorType($property->type);

        return $formatted !== null ? ['Surveyor type: ' . $formatted] : [];
    }

    /**
     * @return list<string>
     */
    private function propertyKindDocumentationFromProperty(PropertyResult $property): array
    {
        $documentation = [
            'Surveyor property kind: ' . $property->visibility,
        ];

        $origins = [];

        if ($property->fromDocBlock) {
            $origins[] = 'docblock';
        }

        if ($property->modelAttribute) {
            $origins[] = 'model attribute';
        }

        if ($property->modelRelation) {
            $origins[] = 'model relation';
        }

        $documentation[] = 'Surveyor property origin: ' . ($origins === [] ? 'native' : implode(', ', $origins));

        return $documentation;
    }

    /**
     * @return list<string>
     */
    private function constantDocumentationFromConstant(ConstantResult $constant): array
    {
        if ($constant->type === null) {
            return [];
        }

        $formatted = $this->formatSurveyorType($constant->type);

        return $formatted !== null ? ['Surveyor type: ' . $formatted] : [];
    }

    private function methodSignatureFromMethod(string $methodName, MethodResult $method): ?string
    {
        $parameters = $method->parameters();
        ksort($parameters);
        $parameterLabels = [];

        foreach ($parameters as $name => $type) {
            $formatted = $this->formatSurveyorType($type);

            if ($formatted === null) {
                continue;
            }

            $parameterLabels[] = $formatted . ' $' . $name;
        }

        $returnType = $this->formatMethodReturnType($method);

        return (
            $methodName
            . '('
            . implode(', ', $parameterLabels)
            . ')'
            . ($returnType === null ? '' : ': ' . $returnType)
        );
    }

    private function propertySignatureFromProperty(string $propertyName, PropertyResult $property): ?string
    {
        if ($property->type === null) {
            return null;
        }

        $formatted = $this->formatSurveyorType($property->type);

        return $formatted !== null ? '$' . $propertyName . ': ' . $formatted : null;
    }

    private function constantSignatureFromConstant(string $constantName, ConstantResult $constant): ?string
    {
        if ($constant->type === null) {
            return null;
        }

        $formatted = $this->formatSurveyorType($constant->type);

        return $formatted !== null ? $constantName . ': ' . $formatted : null;
    }

    /**
     * @param array<string, bool>|array<int, string>|list<string>|mixed $names
     * @return list<string>
     */
    private function normalizeClassNameList(mixed $names): array
    {
        if (!is_array($names)) {
            return [];
        }

        $normalized = [];

        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $normalized[] = ltrim($name, '\\');
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function classMethodNames(ClassResult $class): array
    {
        /** @var list<string> $methods */
        $methods = $this->memoize('class-method-names', $class->name(), function () use ($class): array {
            $methods = [];

            foreach ($class->publicMethods() as $method) {
                if (!$method instanceof MethodResult) {
                    continue;
                }

                $methods[] = $method->name() . '()';
            }

            return $this->normalizeStringList($methods);
        });

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function classPropertyNames(ClassResult $class): array
    {
        /** @var list<string> $properties */
        $properties = $this->memoize('class-property-names', $class->name(), function () use ($class): array {
            $properties = [];

            foreach ($class->publicProperties() as $property) {
                if (!$property instanceof PropertyResult || $property->name === '') {
                    continue;
                }

                $properties[] = '$' . $property->name;
            }

            return $this->normalizeStringList($properties);
        });

        return $properties;
    }

    /**
     * @return list<string>
     */
    private function classConstantNames(string $className, ClassResult $class): array
    {
        /** @var list<string> $names */
        $names = $this->memoize('class-constant-names', $className, function () use ($class): array {
            try {
                $property = self::classResultConstantsProperty();
                $constants = $property->getValue($class);
            } catch (Throwable) {
                return [];
            }

            if (!is_array($constants)) {
                return [];
            }

            $names = [];

            foreach (array_keys($constants) as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }

            return $this->normalizeStringList($names);
        });

        return $names;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $values = array_values(array_filter(array_map(static fn(string $value): string => ltrim(
            $value,
            '\\',
        ), array_values(array_filter($values, static fn(mixed $value): bool => is_string($value) && $value !== '')))));
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param list<string> $values
     */
    private function boundedList(array $values, int $limit): string
    {
        $values = $this->normalizeStringList($values);
        $limit = max(1, $limit);
        $visible = array_slice($values, 0, $limit);
        $hidden = count($values) - count($visible);

        if ($hidden > 0) {
            $visible[] = '+' . $hidden . ' more';
        }

        return implode(', ', $visible);
    }

    private function formatMethodReturnType(MethodResult $method): ?string
    {
        $returnTypes = $method->returnTypes();

        if ($returnTypes === []) {
            return null;
        }

        return $this->formatSurveyorType($method->returnType());
    }

    private function formatSurveyorType(mixed $type): ?string
    {
        if (!is_object($type)) {
            return null;
        }

        $formatted = $this->typeFormatter->format($type);

        return $formatted === '?null' ? 'null' : $formatted;
    }

    private function signatureDocument(?string $signature): ?Document
    {
        return (
            is_string($signature)
            && $signature !== ''
                ? new Document(['language' => 'php', 'text' => $signature])
                : null
        );
    }

    /**
     * @template T
     * @param callable():T $resolver
     * @return T
     */
    private function memoize(string $bucket, string $key, callable $resolver): mixed
    {
        if (array_key_exists($key, $this->formattedCache[$bucket] ?? [])) {
            return $this->formattedCache[$bucket][$key];
        }

        return $this->formattedCache[$bucket][$key] = $resolver();
    }

    private static function classResultConstantsProperty(): ReflectionProperty
    {
        if (self::$classResultConstantsProperty instanceof ReflectionProperty) {
            return self::$classResultConstantsProperty;
        }

        $reflection = new ReflectionClass(ClassResult::class);
        $property = $reflection->getProperty('constants');
        $property->setAccessible(true);

        return self::$classResultConstantsProperty = $property;
    }
}

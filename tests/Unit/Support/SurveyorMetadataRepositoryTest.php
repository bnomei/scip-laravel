<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Analyzed\ConstantResult;
use Laravel\Surveyor\Analyzed\MethodResult;
use Laravel\Surveyor\Analyzed\PropertyResult;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\Types\Type;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SurveyorMetadataRepositoryTest extends TestCase
{
    public function test_class_summary_and_relationship_documentation_are_bounded_and_deterministic(): void
    {
        $class = $this->classResult(
            extends: ['App\\Support\\BaseExample'],
            implements: ['JsonSerializable', 'ArrayAccess'],
            methods: [
                $this->method('alpha'),
                $this->method('beta'),
                $this->method('gamma'),
            ],
            properties: [
                new PropertyResult('title', Type::string(), 'public', true, true, false),
                new PropertyResult('internalNote', Type::string(), 'protected', false, false, true),
            ],
            constants: [
                'STATUS_ACTIVE' => new ConstantResult('STATUS_ACTIVE', Type::string()),
                'STATUS_ARCHIVED' => new ConstantResult('STATUS_ARCHIVED', Type::string()),
            ],
        );

        $repository = $this->repositoryFor($class);

        self::assertSame(
            [
                'extends' => ['App\\Support\\BaseExample'],
                'implements' => ['ArrayAccess', 'JsonSerializable'],
            ],
            $repository->classRelationships('App\\Support\\SurveyorExample'),
        );

        self::assertSame(
            [
                'Surveyor extends: App\\Support\\BaseExample',
                'Surveyor implements: ArrayAccess, JsonSerializable',
                'Surveyor public methods: alpha(), beta(), +1 more',
                'Surveyor public properties: $title',
                'Surveyor constants: STATUS_ACTIVE, STATUS_ARCHIVED',
            ],
            $repository->publicApiSummaryDocumentation('App\\Support\\SurveyorExample', 2),
        );

        self::assertSame(
            [
                'Surveyor property kind: public',
                'Surveyor property origin: docblock, model attribute',
            ],
            $repository->propertyKindDocumentation('App\\Support\\SurveyorExample', 'title'),
        );

        self::assertSame(
            [
                'Surveyor type: string',
            ],
            $repository->propertyDocumentation('App\\Support\\SurveyorExample', 'internalNote'),
        );

        self::assertSame(
            [
                'Surveyor type: string',
            ],
            $repository->propertyDocumentation('App\\Support\\SurveyorExample', 'title'),
        );
    }

    public function test_method_and_constant_documentation_include_parameter_contracts_return_variants_and_signatures(): void
    {
        $class = $this->classResult(methods: [
            $this->method(
                'filter',
                [
                    'term' => Type::string(),
                    'page' => Type::int(),
                ],
                [
                    [Type::string(), 12],
                    [Type::null(), 24],
                ],
                [
                    'term' => [
                        ['required'],
                        ['string'],
                    ],
                ],
            ),
        ], constants: [
            'STATUS_ACTIVE' => new ConstantResult('STATUS_ACTIVE', Type::string()),
        ]);

        $repository = $this->repositoryFor($class);

        self::assertSame(
            [
                'Surveyor return type: ?string',
                'Surveyor validation: term => required|string',
            ],
            $repository->methodDocumentation('App\\Support\\SurveyorExample', 'filter'),
        );

        self::assertSame(
            [
                'Surveyor parameters: page: int, term: string',
            ],
            $repository->methodParameterContractDocumentation('App\\Support\\SurveyorExample', 'filter'),
        );

        self::assertSame(
            [
                'Surveyor return contract: ?string',
            ],
            $repository->methodReturnContractDocumentation('App\\Support\\SurveyorExample', 'filter'),
        );

        self::assertSame(
            [
                'Surveyor return variants: line 12 => ?string; line 24 => null',
            ],
            $repository->methodReturnVariantDocumentation('App\\Support\\SurveyorExample', 'filter'),
        );

        self::assertSame('filter(int $page, string $term): ?string', $repository->methodSignatureText(
            'App\\Support\\SurveyorExample',
            'filter',
        ));

        self::assertSame(
            [
                'documentation' => [
                    'Surveyor parameters: page: int, term: string',
                    'Surveyor return contract: ?string',
                    'Surveyor return type: ?string',
                    'Surveyor return variants: line 12 => ?string; line 24 => null',
                    'Surveyor validation: term => required|string',
                ],
                'signature' => 'filter(int $page, string $term): ?string',
            ],
            $repository->methodMetadataPayload('App\\Support\\SurveyorExample', 'filter'),
        );

        self::assertSame(
            'filter(int $page, string $term): ?string',
            $repository->methodSignatureDocumentation('App\\Support\\SurveyorExample', 'filter')?->getText(),
        );

        self::assertSame(
            [
                'Surveyor type: string',
            ],
            $repository->constantDocumentation('App\\Support\\SurveyorExample', 'STATUS_ACTIVE'),
        );

        self::assertSame('STATUS_ACTIVE: string', $repository->constantSignatureText(
            'App\\Support\\SurveyorExample',
            'STATUS_ACTIVE',
        ));

        self::assertSame(
            [
                'documentation' => [
                    'Surveyor type: string',
                ],
                'signature' => 'STATUS_ACTIVE: string',
            ],
            $repository->constantMetadataPayload('App\\Support\\SurveyorExample', 'STATUS_ACTIVE'),
        );

        self::assertSame(
            'STATUS_ACTIVE: string',
            $repository->constantSignatureDocumentation('App\\Support\\SurveyorExample', 'STATUS_ACTIVE')?->getText(),
        );
    }

    public function test_formatted_output_is_memoized_per_class_and_member(): void
    {
        $method = $this->method(
            'filter',
            [
                'term' => Type::string(),
            ],
            [
                [Type::string(), 12],
            ],
            [
                'term' => [
                    ['required'],
                ],
            ],
        );

        $class = $this->classResult(
            extends: ['App\\Support\\BaseExample'],
            implements: ['JsonSerializable'],
            methods: [$method],
            properties: [
                new PropertyResult('title', Type::string(), 'public', true, true, false),
            ],
            constants: [
                'STATUS_ACTIVE' => new ConstantResult('STATUS_ACTIVE', Type::string()),
            ],
        );

        $repository = $this->repositoryFor($class);

        $summary = $repository->publicApiSummaryDocumentation('App\\Support\\SurveyorExample', 2);
        $parameterDocumentation = $repository->methodParameterContractDocumentation(
            'App\\Support\\SurveyorExample',
            'filter',
        );
        $methodMetadata = $repository->methodMetadataPayload('App\\Support\\SurveyorExample', 'filter');
        $signature = $repository->methodSignatureDocumentation('App\\Support\\SurveyorExample', 'filter')?->getText();
        $constantSignature = $repository
            ->constantSignatureDocumentation('App\\Support\\SurveyorExample', 'STATUS_ACTIVE')
            ?->getText();

        $method->addParameter('page', Type::int());
        $method->addReturnType(Type::null(), 24);
        $method->addValidationRule('term', [['required'], ['string'], ['filled']]);
        $class->addMethod($this->method('omega'));
        $class->addProperty(new PropertyResult('internalNote', Type::string(), 'protected', false, false, true));

        $reflection = new ReflectionProperty(ClassResult::class, 'constants');
        $reflection->setAccessible(true);
        $reflection->setValue($class, [
            'STATUS_ACTIVE' => new ConstantResult('STATUS_ACTIVE', Type::string()),
            'STATUS_ARCHIVED' => new ConstantResult('STATUS_ARCHIVED', Type::string()),
        ]);

        self::assertSame($summary, $repository->publicApiSummaryDocumentation('App\\Support\\SurveyorExample', 2));
        self::assertSame($parameterDocumentation, $repository->methodParameterContractDocumentation(
            'App\\Support\\SurveyorExample',
            'filter',
        ));
        self::assertSame($methodMetadata, $repository->methodMetadataPayload(
            'App\\Support\\SurveyorExample',
            'filter',
        ));
        self::assertSame(
            $signature,
            $repository->methodSignatureDocumentation('App\\Support\\SurveyorExample', 'filter')?->getText(),
        );
        self::assertSame(
            $constantSignature,
            $repository->constantSignatureDocumentation('App\\Support\\SurveyorExample', 'STATUS_ACTIVE')?->getText(),
        );
    }

    private function repositoryFor(ClassResult $classResult): SurveyorMetadataRepository
    {
        $analyzer = new class($classResult) extends Analyzer {
            public function __construct(
                private readonly ClassResult $classResult,
            ) {}

            public function analyzeClass(string $className)
            {
                return new class($this->classResult) {
                    public function __construct(
                        private readonly ClassResult $classResult,
                    ) {}

                    public function result(): ClassResult
                    {
                        return $this->classResult;
                    }
                };
            }
        };

        return new SurveyorMetadataRepository($analyzer);
    }

    /**
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<MethodResult> $methods
     * @param list<PropertyResult> $properties
     * @param array<string, ConstantResult> $constants
     */
    private function classResult(
        array $extends = [],
        array $implements = [],
        array $methods = [],
        array $properties = [],
        array $constants = [],
    ): ClassResult {
        $class = new ClassResult(
            'SurveyorExample',
            'App\\Support',
            $extends,
            $implements,
            [],
            '/tmp/SurveyorExample.php',
        );

        foreach ($methods as $method) {
            $class->addMethod($method);
        }

        foreach ($properties as $property) {
            $class->addProperty($property);
        }

        $reflection = new ReflectionProperty(ClassResult::class, 'constants');
        $reflection->setAccessible(true);
        $reflection->setValue($class, $constants);

        return $class;
    }

    /**
     * @param array<string, Type> $parameters
     * @param list<array{0: Type, 1: int}> $returnTypes
     * @param array<string, list<array<int, mixed>>> $validationRules
     */
    private function method(
        string $name,
        array $parameters = [],
        array $returnTypes = [],
        array $validationRules = [],
    ): MethodResult {
        $method = new MethodResult($name);

        foreach ($parameters as $parameterName => $type) {
            $method->addParameter($parameterName, $type);
        }

        foreach ($returnTypes as [$type, $lineNumber]) {
            $method->addReturnType($type, $lineNumber);
        }

        foreach ($validationRules as $key => $rules) {
            $method->addValidationRule($key, $rules);
        }

        return $method;
    }
}

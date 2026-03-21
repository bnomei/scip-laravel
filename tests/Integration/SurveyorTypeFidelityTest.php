<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Integration;

use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Analyzed\MethodResult;
use Laravel\Surveyor\Analyzed\PropertyResult;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\Types\TemplateTagType;
use Laravel\Surveyor\Types\Type;
use PHPUnit\Framework\TestCase;

final class SurveyorTypeFidelityTest extends TestCase
{
    public function test_repository_propagates_intersection_and_template_tag_types(): void
    {
        $repository = new SurveyorMetadataRepository($this->analyzer());

        self::assertSame(
            ['Surveyor return type: int&string'],
            $repository->methodDocumentation('App\\Support\\SurveyorExample', 'intersecting'),
        );

        self::assertSame(
            ['Surveyor return contract: int&string'],
            $repository->methodReturnContractDocumentation('App\\Support\\SurveyorExample', 'intersecting'),
        );

        self::assertSame('intersecting(): int&string', $repository->methodSignatureText(
            'App\\Support\\SurveyorExample',
            'intersecting',
        ));

        self::assertSame(
            ['Surveyor type: TValue of string super int = array<mixed> - items'],
            $repository->propertyDocumentation('App\\Support\\SurveyorExample', 'templated'),
        );

        self::assertSame('$templated: TValue of string super int = array<mixed> - items', $repository->propertySignatureText(
            'App\\Support\\SurveyorExample',
            'templated',
        ));
    }

    private function analyzer(): Analyzer
    {
        $class = new ClassResult('SurveyorExample', 'App\\Support', [], [], [], '/tmp/SurveyorExample.php');

        $method = new MethodResult('intersecting');
        $method->addReturnType(Type::intersection(Type::string(), Type::int()), 12);
        $class->addMethod($method);
        $class->addProperty(
            new PropertyResult(
                'templated',
                new TemplateTagType('TValue', Type::string(), Type::array([]), Type::int(), 'items'),
            ),
        );

        return new class($class) extends Analyzer {
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
    }
}

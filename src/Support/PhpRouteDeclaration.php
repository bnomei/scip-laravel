<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class PhpRouteDeclaration
{
    public function __construct(
        public string $filePath,
        public ?string $uriLiteral,
        public ?string $nameLiteral,
        public ?SourceRange $nameRange,
        public ?SourceRange $anchorRange,
        public ?SourceRange $targetRange,
        public ?string $controllerClass,
        public ?string $controllerMethod,
        public ?SourceRange $controllerRange,
        public int $controllerSyntaxKind,
        public ?string $viewName = null,
        public ?string $redirectTarget = null,
        public ?string $resourceName = null,
        public ?string $resourceType = null,
        /** @var list<string> */
        public array $generatedRouteNames = [],
        public ?string $componentName = null,
        /** @var array<string, string> */
        public array $parameterDefaults = [],
    ) {}
}

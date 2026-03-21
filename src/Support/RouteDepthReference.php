<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class RouteDepthReference
{
    public function __construct(
        public string $filePath,
        public string $routeName,
        public ?string $scopeBindingsState = null,
        public ?string $missingTargetKind = null,
        public ?string $missingTarget = null,
        public ?SourceRange $missingTargetRange = null,
        public ?string $authorizationAbility = null,
        public ?string $authorizationTargetLiteral = null,
        public ?SourceRange $authorizationTargetRange = null,
        public ?string $authorizationTargetClassName = null,
    ) {}
}

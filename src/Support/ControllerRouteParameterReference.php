<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class ControllerRouteParameterReference
{
    public function __construct(
        public string $filePath,
        public string $controllerClass,
        public string $controllerMethod,
        public string $parameterName,
        public SourceRange $range,
    ) {}
}

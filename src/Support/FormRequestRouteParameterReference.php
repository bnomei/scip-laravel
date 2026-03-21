<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class FormRequestRouteParameterReference
{
    public function __construct(
        public string $filePath,
        public string $className,
        public string $parameterName,
        public SourceRange $range,
        public bool $propertyShortcut,
    ) {}
}

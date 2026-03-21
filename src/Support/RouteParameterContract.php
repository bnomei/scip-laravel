<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

final readonly class RouteParameterContract
{
    /**
     * @param list<string> $types
     * @param list<string> $documentation
     */
    public function __construct(
        public string $routeName,
        public string $parameterName,
        public string $symbol,
        public bool $optional,
        public ?string $placeholder,
        public ?string $defaultValue,
        public ?string $bindingKey,
        public ?string $boundClass,
        public array $types,
        public array $documentation,
    ) {}
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRange;

final readonly class VoltBladePreamble
{
    /**
     * @param array<string, string> $propertyTypes
     * @param array<string, string> $mountParameterTypes
     * @param array<string, string> $computedPropertyTypes
     * @param list<string> $stateNames
     * @param array<string, SourceRange> $propertyRanges
     * @param array<string, SourceRange> $methodRanges
     * @param array<string, list<string>> $propertyMetadata
     * @param array<string, list<string>> $methodMetadata
     * @param list<string> $viewMetadata
     * @param list<BladeLiteralReference> $layoutReferences
     */
    public function __construct(
        public int $bodyOffset,
        public array $propertyTypes,
        public array $mountParameterTypes,
        public array $computedPropertyTypes,
        public array $stateNames,
        public array $propertyRanges = [],
        public array $methodRanges = [],
        public array $propertyMetadata = [],
        public array $methodMetadata = [],
        public array $viewMetadata = [],
        public array $layoutReferences = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Support;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use RuntimeException;

final readonly class ThrowingEnricher implements Enricher
{
    public function __construct(
        private string $featureName,
        private string $message = 'Acceptance failure injection.',
    ) {}

    public function feature(): string
    {
        return $this->featureName;
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        throw new RuntimeException($this->message);
    }
}

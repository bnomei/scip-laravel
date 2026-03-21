<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline;

use Bnomei\ScipLaravel\Application\LaravelContext;

interface Enricher
{
    public function feature(): string;

    public function collect(LaravelContext $context): IndexPatch;
}

<?php

declare(strict_types=1);

namespace App\Contracts;

interface AcceptanceGreeter
{
    public function greet(string $name): string;
}

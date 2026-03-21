<?php

declare(strict_types=1);

namespace App\Support;

use JsonSerializable;

final class AcceptanceSummary implements JsonSerializable
{
    public const DEFAULT_LIMIT = 15;

    public function __construct(
        public readonly int $drafts,
        public readonly int $published,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'drafts' => $this->drafts,
            'limit' => self::DEFAULT_LIMIT,
            'published' => $this->published,
        ];
    }
}

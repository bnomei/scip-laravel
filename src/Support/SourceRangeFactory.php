<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use function strlen;
use function strrpos;
use function substr;
use function substr_count;

final class SourceRangeFactory
{
    public function fromOffsets(string $contents, int $startOffset, int $endOffset): SourceRange
    {
        [$startLine, $startColumn] = $this->positionForOffset($contents, $startOffset);
        [$endLine, $endColumn] = $this->positionForOffset($contents, $endOffset);

        return new SourceRange($startLine, $startColumn, $endLine, $endColumn);
    }

    /**
     * @return array{int, int}
     */
    private function positionForOffset(string $contents, int $offset): array
    {
        $prefix = substr($contents, 0, $offset);
        $line = substr_count($prefix, "\n");
        $lastNewline = strrpos($prefix, "\n");
        $column = $lastNewline === false ? strlen($prefix) : strlen($prefix) - $lastNewline - 1;

        return [$line, $column];
    }
}

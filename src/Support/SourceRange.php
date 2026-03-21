<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use InvalidArgumentException;

use function strlen;
use function strrpos;
use function substr;
use function substr_count;

final readonly class SourceRange
{
    public function __construct(
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
    ) {
        if ($this->startLine < 0 || $this->startColumn < 0 || $this->endLine < 0 || $this->endColumn < 0) {
            throw new InvalidArgumentException('Source ranges must use non-negative positions.');
        }
    }

    public static function fromOffsets(string $contents, int $startOffset, int $endOffset): self
    {
        if ($startOffset < 0 || $endOffset < $startOffset) {
            throw new InvalidArgumentException('Source range offsets must be ordered and non-negative.');
        }

        [$startLine, $startColumn] = self::lineAndColumn($contents, $startOffset);
        [$endLine, $endColumn] = self::lineAndColumn($contents, $endOffset);

        return new self($startLine, $startColumn, $endLine, $endColumn);
    }

    /**
     * @return array{int, int, int, int}
     */
    public function toScipRange(): array
    {
        return [
            $this->startLine,
            $this->startColumn,
            $this->endLine,
            $this->endColumn,
        ];
    }

    /**
     * @return array{int, int}
     */
    private static function lineAndColumn(string $contents, int $offset): array
    {
        $prefix = substr($contents, 0, $offset);
        $line = substr_count($prefix, "\n");
        $lastNewline = strrpos($prefix, "\n");
        $column = $lastNewline === false ? strlen($prefix) : strlen($prefix) - $lastNewline - 1;

        return [$line, $column];
    }
}

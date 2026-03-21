<?php

declare(strict_types=1);

namespace ScipPhp\File;

use RuntimeException;

use function clearstatcache;
use function filesize;
use function file_get_contents;
use function filemtime;

final class Reader
{
    /** @var array<string, array{mtime: int|false, size: int|false, contents: string}> */
    private static array $cache = [];

    /** @param  non-empty-string  $filename */
    public static function read(string $filename): string
    {
        clearstatcache(false, $filename);

        $mtime = @filemtime($filename);
        $size = @filesize($filename);
        $cached = self::$cache[$filename] ?? null;

        if (
            $cached !== null
            && $cached['mtime'] === $mtime
            && $cached['size'] === $size
        ) {
            return $cached['contents'];
        }

        $contents = @file_get_contents($filename);
        if ($contents === false) {
            unset(self::$cache[$filename]);
            throw new RuntimeException("Cannot read file: {$filename}.");
        }

        self::$cache[$filename] = [
            'mtime' => $mtime,
            'size' => $size,
            'contents' => $contents,
        ];

        return $contents;
    }
}

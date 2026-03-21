<?php

declare(strict_types=1);

namespace Tests\File;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ScipPhp\File\Reader;

use function chmod;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

final class ReaderTest extends TestCase
{
    public function testRead(): void
    {
        $contents = Reader::read(__DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'test-file.txt');

        self::assertSame("The quick brown fox jumps\nover the lazy dog", $contents);
    }

    public function testReadNonExistent(): void
    {
        $filename = 'non-existent.txt';

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage("Cannot read file: {$filename}.");

        Reader::read($filename);
    }

    public function testReadUnreadable(): void
    {
        $filename = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'unreadable.txt';

        $result = chmod($filename, 0222);
        self::assertTrue($result);

        self::expectException(RuntimeException::class);
        self::expectExceptionMessage("Cannot read file: {$filename}.");

        try {
            Reader::read($filename);
        } finally {
            chmod($filename, 0422); // Change back to avoid having a pending change in git.
        }
    }

    public function testReadRefreshesChangedFile(): void
    {
        $filename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scip-reader-' . uniqid('', true) . '.txt';

        try {
            file_put_contents($filename, 'first');
            self::assertSame('first', Reader::read($filename));

            file_put_contents($filename, 'second value');
            self::assertSame('second value', Reader::read($filename));
        } finally {
            unlink($filename);
        }
    }
}

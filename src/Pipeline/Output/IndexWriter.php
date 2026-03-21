<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline\Output;

use RuntimeException;
use Scip\Index;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

final class IndexWriter
{
    public function write(Index $index, string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create output directory: %s', $directory));
        }

        $bytes = $index->serializeToString();

        if (file_put_contents($outputPath, $bytes) === false) {
            throw new RuntimeException(sprintf('Could not write SCIP output to %s', $outputPath));
        }
    }
}

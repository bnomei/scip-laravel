<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline\Baseline;

use Scip\Index;
use ScipPhp\Indexer;
use Throwable;

use function is_readable;
use function sprintf;

final class BaselineScipPhpAdapter
{
    /**
     * @param list<string> $arguments
     */
    public function index(string $targetRoot, string $toolVersion, array $arguments): Index
    {
        foreach ([
            'composer.json',
            'composer.lock',
            'vendor/autoload.php',
            'vendor/composer/installed.php',
        ] as $relativePath) {
            $path = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;

            if (!is_readable($path)) {
                throw new BaselineIndexingException(sprintf(
                    'Baseline indexing requires a readable %s in %s.',
                    $relativePath,
                    $targetRoot,
                ));
            }
        }

        try {
            return (new Indexer($targetRoot, $toolVersion, $arguments))->index();
        } catch (Throwable $exception) {
            throw new BaselineIndexingException(
                sprintf('Baseline scip-php indexing failed for %s: %s', $targetRoot, $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}

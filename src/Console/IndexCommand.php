<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Console;

use Bnomei\ScipLaravel\Application\IndexApplication;
use Throwable;

use function count;
use function fwrite;
use function sprintf;

final class IndexCommand
{
    public function __construct(
        private readonly CliInputParser $parser = new CliInputParser(),
        private readonly IndexApplication $application = new IndexApplication(),
    ) {}

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $binary = $argv[0] ?? 'scip-laravel';

        try {
            $input = $this->parser->parse($argv);
        } catch (UsageException $exception) {
            $this->writeError($exception->getMessage());
            $this->writeError('');
            $this->writeOutput($this->helpText($binary));

            return 1;
        }

        if ($input->help) {
            $this->writeOutput($this->helpText($binary));

            return 0;
        }

        try {
            $result = $this->application->execute($input);
        } catch (Throwable $exception) {
            $this->writeError($exception->getMessage());

            return 1;
        }

        foreach ($result->warnings as $warning) {
            $label = $warning->code !== null ? sprintf('[%s] ', $warning->code) : '';

            $this->writeError(sprintf('Warning: %s%s', $label, $warning->message));
        }

        $this->writeOutput(sprintf(
            'Wrote %s (%d document%s).',
            $result->outputPath,
            $result->documentCount,
            $result->documentCount === 1 ? '' : 's',
        ));

        return 0;
    }

    private function helpText(string $binary): string
    {
        return <<<TEXT
            usage: {$binary} [--output=index.scip] [--config=scip-laravel.php] [--mode=safe|full] [--strict] [--feature=models,routes,views,inertia,broadcast,config,translations,env] [target-root]

            Generate a Laravel-aware SCIP index.

            Options:
              -h, --help               Display this help and exit
                  --output=PATH        Write the final index to PATH (defaults to target-root/index.scip)
                  --config=PATH        Load configuration from PATH (defaults to target-root/scip-laravel.php)
                  --mode=MODE          Use the runtime mode safe or full (defaults to full)
                  --strict             Treat enabled enricher failures as fatal once implemented
                  --feature=LIST       Limit enabled feature families with a comma-separated list

            TEXT;
    }

    private function writeOutput(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    private function writeError(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Throwable;

use function interface_exists;
use function is_file;
use function is_object;
use function method_exists;
use function sprintf;

final class LaravelBootstrapper
{
    public function bootstrap(string $targetRoot): BootstrappedLaravelApplication
    {
        $autoloadPath = $targetRoot . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
        $bootstrapPath = $targetRoot . DIRECTORY_SEPARATOR . 'bootstrap/app.php';

        if (!is_file($autoloadPath)) {
            throw new BootstrapException(sprintf(
                'Laravel dependencies are not installed for %s. Missing vendor/autoload.php.',
                $targetRoot,
            ));
        }

        try {
            require_once $autoloadPath;

            $application = require $bootstrapPath;
        } catch (Throwable $exception) {
            throw new BootstrapException(
                sprintf('Failed to bootstrap the Laravel application at %s: %s', $targetRoot, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_object($application) || !method_exists($application, 'make')) {
            throw new BootstrapException(sprintf(
                'bootstrap/app.php did not return a bootstrapable Laravel application for %s.',
                $targetRoot,
            ));
        }

        $foundationContract = 'Illuminate\\Contracts\\Foundation\\Application';

        if (interface_exists($foundationContract) && !$application instanceof $foundationContract) {
            throw new BootstrapException(sprintf(
                'bootstrap/app.php returned %s, which does not implement %s.',
                $application::class,
                $foundationContract,
            ));
        }

        $consoleKernel = $this->bootstrapConsoleKernel($application, $targetRoot);

        return new BootstrappedLaravelApplication($application, $consoleKernel);
    }

    private function bootstrapConsoleKernel(object $application, string $targetRoot): ?object
    {
        $kernelContract = 'Illuminate\\Contracts\\Console\\Kernel';

        if (!interface_exists($kernelContract)) {
            return null;
        }

        try {
            $consoleKernel = $application->make($kernelContract);
        } catch (Throwable $exception) {
            throw new BootstrapException(
                sprintf(
                    'Failed to resolve the Laravel console kernel for %s: %s',
                    $targetRoot,
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        if (!is_object($consoleKernel)) {
            throw new BootstrapException(sprintf(
                'The Laravel console kernel could not be resolved for %s.',
                $targetRoot,
            ));
        }

        if (method_exists($consoleKernel, 'bootstrap')) {
            try {
                $consoleKernel->bootstrap();
            } catch (Throwable $exception) {
                throw new BootstrapException(
                    sprintf(
                        'Failed to bootstrap the Laravel console kernel for %s: %s',
                        $targetRoot,
                        $exception->getMessage(),
                    ),
                    previous: $exception,
                );
            }
        }

        return $consoleKernel;
    }
}

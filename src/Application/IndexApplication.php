<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Application;

use Bnomei\ScipLaravel\Config\ConfigLoader;
use Bnomei\ScipLaravel\Console\CliInput;
use Bnomei\ScipLaravel\Pipeline\IndexPipeline;
use Bnomei\ScipLaravel\Runtime\RuntimeModeApplier;

final class IndexApplication
{
    public function __construct(
        private readonly RootLocator $rootLocator = new RootLocator(),
        private readonly ConfigLoader $configLoader = new ConfigLoader(),
        private readonly LaravelBootstrapper $bootstrapper = new LaravelBootstrapper(),
        private readonly RuntimeModeApplier $runtimeModeApplier = new RuntimeModeApplier(),
        private readonly IndexPipeline $pipeline = new IndexPipeline(),
    ) {}

    public function prepare(CliInput $input): PreparedRun
    {
        $targetRoot = $this->rootLocator->resolve($input->targetRoot);
        $config = $this->configLoader->load($targetRoot, $input);
        $bootstrapped = $this->bootstrapper->bootstrap($targetRoot);

        return new PreparedRun(
            targetRoot: $targetRoot,
            config: $config,
            application: $bootstrapped->application,
            consoleKernel: $bootstrapped->consoleKernel,
        );
    }

    public function execute(CliInput $input): ExecutionResult
    {
        $targetRoot = $this->rootLocator->resolve($input->targetRoot);
        $config = $this->configLoader->load($targetRoot, $input);
        $overrideSnapshot = $this->runtimeModeApplier->apply($targetRoot, $config);

        try {
            $bootstrapped = $this->bootstrapper->bootstrap($targetRoot);

            return $this->pipeline->run(new PreparedRun(
                targetRoot: $targetRoot,
                config: $config,
                application: $bootstrapped->application,
                consoleKernel: $bootstrapped->consoleKernel,
            ));
        } finally {
            $overrideSnapshot->restore();
        }
    }
}

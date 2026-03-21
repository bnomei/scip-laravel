<?php

declare(strict_types=1);

use Bnomei\ScipLaravel\Application\LaravelBootstrapper;
use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\RootLocator;
use Bnomei\ScipLaravel\Config\ConfigLoader;
use Bnomei\ScipLaravel\Console\CliInput;
use Bnomei\ScipLaravel\Pipeline\IndexPipeline;
use Bnomei\ScipLaravel\Runtime\RuntimeModeApplier;
use Bnomei\ScipLaravel\Support\DiagnosticsSink;
use Bnomei\ScipLaravel\Support\RuntimeCacheRegistry;
use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Composer\InstalledVersions;
use Laravel\Surveyor\Analyzer\Analyzer;

$repositoryRoot = dirname(__DIR__, 2);

require $repositoryRoot . '/vendor/autoload.php';

$targetRoot = $repositoryRoot . '/fixtures/_worktrees/flux-app';
$outputPath = '/tmp/flux-profile.scip';
$warmup = 1;
$iterations = 5;
$mode = null;
$strict = false;
$features = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $outputPath = substr($argument, strlen('--output='));
        continue;
    }

    if (str_starts_with($argument, '--warmup=') || str_starts_with($argument, '--warmups=')) {
        $prefix = str_starts_with($argument, '--warmups=') ? '--warmups=' : '--warmup=';
        $warmup = max(0, (int) substr($argument, strlen($prefix)));
        continue;
    }

    if (str_starts_with($argument, '--iterations=') || str_starts_with($argument, '--repeats=')) {
        $prefix = str_starts_with($argument, '--repeats=') ? '--repeats=' : '--iterations=';
        $iterations = max(1, (int) substr($argument, strlen($prefix)));
        continue;
    }

    if (str_starts_with($argument, '--mode=')) {
        $mode = substr($argument, strlen('--mode='));
        continue;
    }

    if ($argument === '--strict') {
        $strict = true;
        continue;
    }

    if (str_starts_with($argument, '--feature=')) {
        $features = array_values(array_filter(explode(',', substr($argument, strlen('--feature=')))));
        continue;
    }

    if ($argument === '--help') {
        fwrite(STDOUT, <<<TXT
Usage: php fixtures/flux-app/profile.php [target_dir] [--output=/tmp/flux-profile.scip] [--warmup=1|--warmups=1] [--iterations=5|--repeats=5] [--mode=full|safe] [--feature=a,b] [--strict]

TXT);
        exit(0);
    }

    if ($argument !== '' && $argument[0] !== '-') {
        $targetRoot = $argument;
    }
}

if (! file_exists($targetRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Fixture not prepared: {$targetRoot}\n");
    exit(1);
}

$input = new CliInput(
    help: false,
    targetRoot: $targetRoot,
    outputPath: $outputPath,
    configPath: null,
    mode: $mode,
    strict: $strict,
    features: $features,
);

$runs = [];

for ($index = 0; $index < $warmup; $index++) {
    profileRun($input);
}

for ($index = 0; $index < $iterations; $index++) {
    $runs[] = profileRun($input);
}

$medians = medians($runs);
$means = means($runs);
arsort($medians);

fwrite(STDOUT, sprintf("Flux fixture profile\nroot: %s\nwarmup: %d\niterations: %d\n\n", $targetRoot, $warmup, $iterations));

foreach ($runs as $index => $run) {
    fwrite(STDOUT, sprintf(
        "run %d\ttotal %.2f ms\tbaseline %.2f\tranger %.2f\tmerge %.2f\twrite %.2f\n",
        $index + 1,
        $run['total'] ?? 0.0,
        $run['baseline'] ?? 0.0,
        $run['ranger'] ?? 0.0,
        $run['merge'] ?? 0.0,
        $run['write'] ?? 0.0,
    ));
}

fwrite(STDOUT, "\nMedian buckets\n");

foreach ($medians as $name => $median) {
    fwrite(STDOUT, sprintf("%s\t%.2f ms\t(mean %.2f)\n", $name, $median, $means[$name] ?? $median));
}

/**
 * @return array<string, float>
 */
function profileRun(CliInput $input): array
{
    $rootLocator = new RootLocator();
    $configLoader = new ConfigLoader();
    $runtimeModeApplier = new RuntimeModeApplier();
    $bootstrapper = new LaravelBootstrapper();
    $pipeline = new IndexPipeline();

    $pipelineReflection = new ReflectionClass($pipeline);
    $baselineAdapter = propertyValue($pipelineReflection, $pipeline, 'baselineAdapter');
    $toolingRegistrar = propertyValue($pipelineReflection, $pipeline, 'toolingRegistrar');
    $rangerSnapshotFactory = propertyValue($pipelineReflection, $pipeline, 'rangerSnapshotFactory');
    $merger = propertyValue($pipelineReflection, $pipeline, 'merger');
    $writer = propertyValue($pipelineReflection, $pipeline, 'writer');
    $enrichers = propertyValue($pipelineReflection, $pipeline, 'enrichers');

    $targetRoot = $rootLocator->resolve($input->targetRoot);
    $config = $configLoader->load($targetRoot, $input);
    $overrideSnapshot = $runtimeModeApplier->apply($targetRoot, $config);
    $timings = [];
    $totalStart = hrtime(true);

    try {
        $start = hrtime(true);
        $bootstrapped = $bootstrapper->bootstrap($targetRoot);
        $timings['bootstrap'] = elapsedMilliseconds($start);

        RuntimeCacheRegistry::reset();
        $diagnostics = new DiagnosticsSink();
        $toolingRegistrar->register($bootstrapped->application, $targetRoot);

        /** @var Analyzer $analyzer */
        $analyzer = $bootstrapped->application->make(Analyzer::class);

        $start = hrtime(true);
        $baselineIndex = $baselineAdapter->index($targetRoot, toolVersion(), normalizedArguments($targetRoot, $config));
        $timings['baseline'] = elapsedMilliseconds($start);

        $start = hrtime(true);
        $rangerSnapshot = $rangerSnapshotFactory->collect($targetRoot, $config->features);
        $timings['ranger'] = elapsedMilliseconds($start);

        $context = new LaravelContext(
            projectRoot: $targetRoot,
            config: $config,
            mode: $config->mode,
            application: $bootstrapped->application,
            consoleKernel: $bootstrapped->consoleKernel,
            analyzer: $analyzer,
            surveyor: new SurveyorMetadataRepository($analyzer),
            rangerSnapshot: $rangerSnapshot,
            baselineIndex: $baselineIndex,
            diagnostics: $diagnostics,
            enabledFeatures: $config->features,
        );

        $patches = [];

        foreach ($enrichers as $enricher) {
            if (! $context->hasFeature($enricher->feature())) {
                continue;
            }

            $start = hrtime(true);
            $patches[] = $enricher->collect($context);
            $timings[(new ReflectionClass($enricher))->getShortName()] = elapsedMilliseconds($start);
        }

        $start = hrtime(true);
        $finalIndex = $merger->merge($baselineIndex, ...$patches);
        $timings['merge'] = elapsedMilliseconds($start);

        $start = hrtime(true);
        $writer->write($finalIndex, $config->outputPath);
        $timings['write'] = elapsedMilliseconds($start);
    } finally {
        $overrideSnapshot->restore();
    }

    $timings['total'] = elapsedMilliseconds($totalStart);

    return $timings;
}

function elapsedMilliseconds(int $start): float
{
    return (hrtime(true) - $start) / 1e6;
}

function toolVersion(): string
{
    return InstalledVersions::getPrettyVersion('bnomei/scip-laravel')
        ?? InstalledVersions::getRootPackage()['pretty_version']
        ?? 'dev-main';
}

/**
 * @return list<string>
 */
function normalizedArguments(string $targetRoot, object $config): array
{
    return [
        '--output=' . $config->outputPath,
        '--config=' . $config->configPath,
        '--mode=' . $config->mode->value,
        ...($config->strict ? ['--strict'] : []),
        '--feature=' . implode(',', $config->features),
        $targetRoot,
    ];
}

function propertyValue(ReflectionClass $reflection, object $instance, string $name): mixed
{
    $property = $reflection->getProperty($name);
    $property->setAccessible(true);

    return $property->getValue($instance);
}

/**
 * @param list<array<string, float>> $runs
 * @return array<string, float>
 */
function medians(array $runs): array
{
    $columns = [];

    foreach ($runs as $run) {
        foreach ($run as $name => $value) {
            $columns[$name][] = $value;
        }
    }

    $medians = [];

    foreach ($columns as $name => $values) {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $medians[$name] = $count % 2 === 1
            ? $values[$middle]
            : (($values[$middle - 1] + $values[$middle]) / 2);
    }

    return $medians;
}

/**
 * @param list<array<string, float>> $runs
 * @return array<string, float>
 */
function means(array $runs): array
{
    $columns = [];

    foreach ($runs as $run) {
        foreach ($run as $name => $value) {
            $columns[$name][] = $value;
        }
    }

    $means = [];

    foreach ($columns as $name => $values) {
        $means[$name] = array_sum($values) / count($values);
    }

    ksort($means);

    return $means;
}

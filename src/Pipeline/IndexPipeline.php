<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Pipeline;

use Bnomei\ScipLaravel\Application\ExecutionResult;
use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Application\LaravelToolingRegistrar;
use Bnomei\ScipLaravel\Application\PreparedRun;
use Bnomei\ScipLaravel\Application\RangerSnapshotFactory;
use Bnomei\ScipLaravel\Enrichers\Async\AsyncGraphEnricher;
use Bnomei\ScipLaravel\Enrichers\Authorization\BladeAuthorizationEnricher;
use Bnomei\ScipLaravel\Enrichers\Authorization\MiddlewareAuthorizationEnricher;
use Bnomei\ScipLaravel\Enrichers\Broadcast\BroadcastEnricher;
use Bnomei\ScipLaravel\Enrichers\Config\ConfigEnricher;
use Bnomei\ScipLaravel\Enrichers\Console\ConsoleGraphEnricher;
use Bnomei\ScipLaravel\Enrichers\Container\ContainerEnricher;
use Bnomei\ScipLaravel\Enrichers\Enums\EnumEnricher;
use Bnomei\ScipLaravel\Enrichers\Env\EnvEnricher;
use Bnomei\ScipLaravel\Enrichers\Inertia\InertiaEnricher;
use Bnomei\ScipLaravel\Enrichers\Livewire\LivewireAttributesEnricher;
use Bnomei\ScipLaravel\Enrichers\Livewire\LivewireEventEnricher;
use Bnomei\ScipLaravel\Enrichers\Livewire\LivewireUiSurfaceEnricher;
use Bnomei\ScipLaravel\Enrichers\Livewire\ValidationEnricher;
use Bnomei\ScipLaravel\Enrichers\Metadata\CanonicalPhpMetadataEnricher;
use Bnomei\ScipLaravel\Enrichers\Models\ModelEnricher;
use Bnomei\ScipLaravel\Enrichers\Routes\RouteDepthEnricher;
use Bnomei\ScipLaravel\Enrichers\Routes\RouteEnricher;
use Bnomei\ScipLaravel\Enrichers\Translations\TranslationEnricher;
use Bnomei\ScipLaravel\Enrichers\Validation\FormRequestEnricher;
use Bnomei\ScipLaravel\Enrichers\Views\BladeContractsEnricher;
use Bnomei\ScipLaravel\Enrichers\Views\ViewEnricher;
use Bnomei\ScipLaravel\Pipeline\Baseline\BaselineScipPhpAdapter;
use Bnomei\ScipLaravel\Pipeline\Merge\IndexMerger;
use Bnomei\ScipLaravel\Pipeline\Output\IndexWriter;
use Bnomei\ScipLaravel\Pipeline\Output\ScipOutputFidelityNormalizer;
use Bnomei\ScipLaravel\Support\DiagnosticsSink;
use Bnomei\ScipLaravel\Support\RuntimeCacheRegistry;
use Bnomei\ScipLaravel\Support\SurveyorMetadataRepository;
use Composer\InstalledVersions;
use Laravel\Surveyor\Analyzer\Analyzer;
use Throwable;

use function count;

final class IndexPipeline
{
    /**
     * @param list<Enricher> $enrichers
     */
    public function __construct(
        private readonly BaselineScipPhpAdapter $baselineAdapter = new BaselineScipPhpAdapter(),
        private readonly LaravelToolingRegistrar $toolingRegistrar = new LaravelToolingRegistrar(),
        private readonly RangerSnapshotFactory $rangerSnapshotFactory = new RangerSnapshotFactory(),
        private readonly IndexMerger $merger = new IndexMerger(),
        private readonly ScipOutputFidelityNormalizer $outputNormalizer = new ScipOutputFidelityNormalizer(),
        private readonly IndexWriter $writer = new IndexWriter(),
        private readonly array $enrichers = [
            new ModelEnricher(),
            new EnumEnricher(),
            new ContainerEnricher(),
            new AsyncGraphEnricher(),
            new ConsoleGraphEnricher(),
            new RouteEnricher(),
            new RouteDepthEnricher(),
            new MiddlewareAuthorizationEnricher(),
            new FormRequestEnricher(),
            new CanonicalPhpMetadataEnricher(),
            new ViewEnricher(),
            new BladeContractsEnricher(),
            new BladeAuthorizationEnricher(),
            new LivewireAttributesEnricher(),
            new LivewireEventEnricher(),
            new LivewireUiSurfaceEnricher(),
            new ValidationEnricher(),
            new InertiaEnricher(),
            new BroadcastEnricher(),
            new ConfigEnricher(),
            new TranslationEnricher(),
            new EnvEnricher(),
        ],
    ) {}

    public function run(PreparedRun $prepared): ExecutionResult
    {
        RuntimeCacheRegistry::reset();
        $diagnostics = new DiagnosticsSink();
        $this->toolingRegistrar->register($prepared->application, $prepared->targetRoot);

        /** @var Analyzer $analyzer */
        $analyzer = $prepared->application->make(Analyzer::class);

        $baselineIndex = $this->baselineAdapter->index(
            $prepared->targetRoot,
            $this->toolVersion(),
            $this->normalizedArguments($prepared),
        );

        $rangerSnapshot = $this->rangerSnapshotFactory->collect($prepared->targetRoot, $prepared->config->features);

        $context = new LaravelContext(
            projectRoot: $prepared->targetRoot,
            config: $prepared->config,
            mode: $prepared->config->mode,
            application: $prepared->application,
            consoleKernel: $prepared->consoleKernel,
            analyzer: $analyzer,
            surveyor: new SurveyorMetadataRepository($analyzer),
            rangerSnapshot: $rangerSnapshot,
            baselineIndex: $baselineIndex,
            diagnostics: $diagnostics,
            enabledFeatures: $prepared->config->features,
        );

        $patches = [];

        foreach ($this->enrichers as $enricher) {
            if (!$context->hasFeature($enricher->feature())) {
                continue;
            }

            try {
                $patches[] = $enricher->collect($context);
            } catch (Throwable $exception) {
                if ($prepared->config->strict) {
                    throw $exception;
                }

                $diagnostics->warning(
                    source: $enricher::class,
                    message: $exception->getMessage(),
                    code: 'enricher-failed',
                );
            }
        }

        foreach ($patches as $patch) {
            foreach ($patch->warnings as $warning) {
                $diagnostics->warning($warning->source, $warning->message, $warning->code);
            }
        }

        $finalIndex = $this->merger->merge($baselineIndex, ...$patches);
        $finalIndex = $this->outputNormalizer->normalize($finalIndex, $this->toolVersion());
        $this->writer->write($finalIndex, $prepared->config->outputPath);

        return new ExecutionResult(
            outputPath: $prepared->config->outputPath,
            documentCount: count($finalIndex->getDocuments()),
            warnings: $diagnostics->warnings(),
        );
    }

    /**
     * @return list<string>
     */
    private function normalizedArguments(PreparedRun $prepared): array
    {
        return [
            '--output=' . $prepared->config->outputPath,
            '--config=' . $prepared->config->configPath,
            '--mode=' . $prepared->config->mode->value,
            ...($prepared->config->strict ? ['--strict'] : []),
            '--feature=' . implode(',', $prepared->config->features),
            $prepared->targetRoot,
        ];
    }

    private function toolVersion(): string
    {
        return (
            InstalledVersions::getPrettyVersion(
                'bnomei/scip-laravel',
            ) ?? InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev-main'
        );
    }
}

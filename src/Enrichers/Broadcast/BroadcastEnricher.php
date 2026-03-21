<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Enrichers\Broadcast;

use Bnomei\ScipLaravel\Application\LaravelContext;
use Bnomei\ScipLaravel\Pipeline\DocumentOccurrencePatch;
use Bnomei\ScipLaravel\Pipeline\DocumentSymbolPatch;
use Bnomei\ScipLaravel\Pipeline\Enricher;
use Bnomei\ScipLaravel\Pipeline\IndexPatch;
use Bnomei\ScipLaravel\Support\BaselineClassSymbolResolver;
use Bnomei\ScipLaravel\Support\PhpLiteralCall;
use Bnomei\ScipLaravel\Support\PhpLiteralCallFinder;
use Bnomei\ScipLaravel\Support\PhpLiteralInstantiationFinder;
use Bnomei\ScipLaravel\Support\SurveyorTypeFormatter;
use Bnomei\ScipLaravel\Support\TopLevelTypeContractFormatter;
use Bnomei\ScipLaravel\Symbols\ProjectSymbolPackageResolver;
use Bnomei\ScipLaravel\Symbols\SyntheticSymbolNormalizer;
use Closure;
use Laravel\Ranger\Components\BroadcastChannel;
use Laravel\Ranger\Components\BroadcastEvent;
use Laravel\Surveyor\Types\Contracts\Type as SurveyorType;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use Scip\Occurrence;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;
use Scip\SymbolRole;
use Scip\SyntaxKind;

use function array_key_exists;
use function count;
use function is_object;
use function is_string;
use function ksort;
use function ltrim;
use function method_exists;
use function sort;
use function str_starts_with;

final class BroadcastEnricher implements Enricher
{
    /**
     * @var list<string>
     */
    private const CHANNEL_CLASSES = [
        'Illuminate\\Broadcasting\\Channel',
        'Illuminate\\Broadcasting\\PrivateChannel',
        'Illuminate\\Broadcasting\\PresenceChannel',
        'Illuminate\\Broadcasting\\EncryptedPrivateChannel',
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $eventDocumentationCache = [];

    /**
     * @var array<string, list<string>>
     */
    private array $payloadDocumentationCache = [];

    public function __construct(
        private readonly ProjectSymbolPackageResolver $packageResolver = new ProjectSymbolPackageResolver(),
        private readonly PhpLiteralCallFinder $callFinder = new PhpLiteralCallFinder(),
        private readonly PhpLiteralInstantiationFinder $instantiationFinder = new PhpLiteralInstantiationFinder(),
        private readonly BaselineClassSymbolResolver $classSymbolResolver = new BaselineClassSymbolResolver(),
        private readonly SurveyorTypeFormatter $typeFormatter = new SurveyorTypeFormatter(),
        private readonly TopLevelTypeContractFormatter $contractFormatter = new TopLevelTypeContractFormatter(),
    ) {}

    public function feature(): string
    {
        return 'broadcast';
    }

    public function collect(LaravelContext $context): IndexPatch
    {
        $this->eventDocumentationCache = [];
        $this->payloadDocumentationCache = [];
        $normalizer = new SyntheticSymbolNormalizer($this->packageResolver->resolve($context->projectRoot));
        $channelsByName = $this->discoveredChannels($context);
        $channelNames = array_fill_keys(array_keys($channelsByName), true);

        if ($channelNames === []) {
            return $this->eventMetadataPatch($context, $normalizer);
        }

        $definitionsByName = [];

        foreach ($this->callFinder->find(
            $context->projectRoot,
            [],
            [
                'Illuminate\\Support\\Facades\\Broadcast' => ['channel'],
            ],
        ) as $call) {
            if (!array_key_exists($call->literal, $channelNames)) {
                continue;
            }

            $definitionsByName[$call->literal] ??= [];
            $definitionsByName[$call->literal][] = $call;
        }

        if ($definitionsByName === []) {
            return IndexPatch::empty();
        }

        ksort($definitionsByName);
        $symbols = [];
        $occurrences = [];
        $symbolsByChannel = [];
        $channelDocumentation = $this->channelDocumentationByName($channelsByName);

        foreach ($definitionsByName as $channelName => $definitions) {
            if (count($definitions) !== 1) {
                continue;
            }

            $definition = $definitions[0];
            $symbol = $normalizer->broadcastChannel($channelName);
            $symbolsByChannel[$channelName] = $symbol;
            $relativePath = $context->relativeProjectPath($definition->filePath);

            $symbols[] = new DocumentSymbolPatch(documentPath: $relativePath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'display_name' => $channelName,
                'kind' => Kind::Key,
                'documentation' => $channelDocumentation[$channelName] ?? [],
            ]));
            $occurrences[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                'range' => $definition->range->toScipRange(),
                'symbol' => $symbol,
                'symbol_roles' => SymbolRole::Definition,
                'syntax_kind' => SyntaxKind::StringLiteralKey,
            ]));

            $ownerSymbol = $this->resolvedChannelOwnerSymbol($context, $channelsByName[$channelName] ?? null);

            if ($ownerSymbol !== null) {
                $occurrences[] = new DocumentOccurrencePatch(documentPath: $relativePath, occurrence: new Occurrence([
                    'range' => $definition->range->toScipRange(),
                    'symbol' => $ownerSymbol,
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::Identifier,
                ]));
            }
        }

        foreach ($this->eventChannelInstantiations($context) as $instantiation) {
            if (!array_key_exists($instantiation->literal, $symbolsByChannel)) {
                continue;
            }

            $occurrences[] = new DocumentOccurrencePatch(
                documentPath: $context->relativeProjectPath($instantiation->filePath),
                occurrence: new Occurrence([
                    'range' => $instantiation->range->toScipRange(),
                    'symbol' => $symbolsByChannel[$instantiation->literal],
                    'symbol_roles' => SymbolRole::ReadAccess,
                    'syntax_kind' => SyntaxKind::StringLiteralKey,
                ]),
            );
        }

        $eventPatch = $this->eventMetadataPatch($context, $normalizer);

        return new IndexPatch(
            symbols: [...$symbols, ...$eventPatch->symbols],
            occurrences: [...$occurrences, ...$eventPatch->occurrences],
            documents: $eventPatch->documents,
            externalSymbols: $eventPatch->externalSymbols,
        );
    }

    /**
     * @return array<string, true|BroadcastChannel|object>
     */
    private function discoveredChannels(LaravelContext $context): array
    {
        $channels = [];

        foreach ($context->rangerSnapshot->broadcastChannels as $channel) {
            if ($channel instanceof BroadcastChannel) {
                $channels[$channel->name] = $channel;
                continue;
            }

            if (!is_object($channel)) {
                continue;
            }

            $name = $channel->name ?? null;

            if (is_string($name) && $name !== '') {
                $channels[$name] = $channel;
            }
        }

        ksort($channels);

        return $channels;
    }

    /**
     * @return list<PhpLiteralCall>
     */
    private function eventChannelInstantiations(LaravelContext $context): array
    {
        $eventFiles = [];

        foreach ($context->rangerSnapshot->broadcastEvents as $event) {
            if ($event instanceof BroadcastEvent && $event->filePath() !== '') {
                $eventFiles[$event->filePath()] = $event->filePath();
                continue;
            }

            if (!is_object($event) || !method_exists($event, 'filePath')) {
                continue;
            }

            $filePath = $event->filePath();

            if (is_string($filePath) && $filePath !== '') {
                $eventFiles[$filePath] = $filePath;
            }
        }

        if ($eventFiles === []) {
            return [];
        }

        $instantiations = [];

        foreach ($this->instantiationFinder->findInFiles(
            array_values($eventFiles),
            self::CHANNEL_CLASSES,
        ) as $instantiation) {
            $instantiations[] = new PhpLiteralCall(
                filePath: $instantiation->filePath,
                callee: $instantiation->className,
                literal: $instantiation->literal,
                range: $instantiation->range,
            );
        }

        return $instantiations;
    }

    /**
     * @param array<string, true|BroadcastChannel|object> $channelsByName
     * @return array<string, list<string>>
     */
    private function channelDocumentationByName(array $channelsByName): array
    {
        $documentation = [];

        foreach ($channelsByName as $name => $channel) {
            if (!$channel instanceof BroadcastChannel) {
                continue;
            }

            $lines = [];

            $ownerClass = $this->resolvedChannelOwnerClassName($channel->resolvesTo);

            if ($ownerClass !== null) {
                $lines[] = 'Broadcast resolves to: ' . $ownerClass;
            } elseif ($channel->resolvesTo instanceof SurveyorType) {
                $lines[] = 'Broadcast resolves to: ' . $this->typeFormatter->format($channel->resolvesTo);
            }

            if ($lines !== []) {
                sort($lines);
                $documentation[$name] = $lines;
            }
        }

        ksort($documentation);

        return $documentation;
    }

    private function eventMetadataPatch(LaravelContext $context, SyntheticSymbolNormalizer $normalizer): IndexPatch
    {
        $symbols = [];
        $occurrences = [];

        foreach ($context->rangerSnapshot->broadcastEvents as $event) {
            if (!$event instanceof BroadcastEvent || $event->className === '') {
                continue;
            }

            $reflection = $this->reflectionForClass($event->className);

            if (!$reflection instanceof ReflectionClass) {
                continue;
            }

            $filePath = $reflection->getFileName();

            if (!is_string($filePath) || $filePath === '') {
                continue;
            }

            $documentPath = $context->relativeProjectPath($filePath);

            if (!str_starts_with($documentPath, 'app/')) {
                continue;
            }

            $symbol = $this->classSymbolResolver->resolve(
                $context->baselineIndex,
                $documentPath,
                ltrim($event->className, '\\'),
                $reflection->getStartLine(),
            );

            if (!is_string($symbol) || $symbol === '') {
                continue;
            }

            $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                'symbol' => $symbol,
                'documentation' => $this->eventDocumentation($event),
            ]));

            $payloadDocumentation = $this->payloadDocumentation($event);

            if ($payloadDocumentation !== []) {
                $payloadSymbol = $normalizer->broadcastPayload($event->name);
                $payloadLine = $this->broadcastPayloadLine($reflection);

                $symbols[] = new DocumentSymbolPatch(documentPath: $documentPath, symbol: new SymbolInformation([
                    'symbol' => $payloadSymbol,
                    'display_name' => 'payload',
                    'kind' => Kind::Key,
                    'documentation' => $payloadDocumentation,
                    'enclosing_symbol' => $symbol,
                ]));

                if ($payloadLine > 0) {
                    $occurrences[] =
                        new DocumentOccurrencePatch(documentPath: $documentPath, occurrence: new Occurrence([
                            'range' => [$payloadLine - 1, 0, $payloadLine - 1, 1],
                            'symbol' => $payloadSymbol,
                            'symbol_roles' => SymbolRole::Definition,
                            'syntax_kind' => SyntaxKind::Identifier,
                            'enclosing_range' => [$payloadLine - 1, 0, $payloadLine - 1, 1],
                        ]));
                }
            }
        }

        return $symbols === [] && $occurrences === []
            ? IndexPatch::empty()
            : new IndexPatch(symbols: $symbols, occurrences: $occurrences);
    }

    private function resolvedChannelOwnerSymbol(LaravelContext $context, mixed $channel): ?string
    {
        if (!$channel instanceof BroadcastChannel) {
            return null;
        }

        $ownerClass = $this->resolvedChannelOwnerClassName($channel->resolvesTo);

        if ($ownerClass === null) {
            return null;
        }

        $reflection = $this->reflectionForClass($ownerClass);

        if (!$reflection instanceof ReflectionClass) {
            return null;
        }

        $filePath = $reflection->getFileName();

        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $documentPath = $context->relativeProjectPath($filePath);

        if (!str_starts_with($documentPath, 'app/')) {
            return null;
        }

        return $this->classSymbolResolver->resolve(
            $context->baselineIndex,
            $documentPath,
            $ownerClass,
            $reflection->getStartLine(),
        );
    }

    private function resolvedChannelOwnerClassName(mixed $owner): ?string
    {
        if (is_string($owner) && $owner !== '') {
            return ltrim($owner, '\\');
        }

        if (!$owner instanceof Closure) {
            return null;
        }

        $reflection = new ReflectionFunction($owner);

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            return ltrim($type->getName(), '\\');
        }

        return null;
    }

    private function reflectionForClass(string $className): ?ReflectionClass
    {
        try {
            return new ReflectionClass(ltrim($className, '\\'));
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function eventDocumentation(BroadcastEvent $event): array
    {
        $cacheKey = $event->className . "\0" . $event->name;

        if (array_key_exists($cacheKey, $this->eventDocumentationCache)) {
            return $this->eventDocumentationCache[$cacheKey];
        }

        $documentation = ['Broadcast event: ' . $event->name];
        $payload = $this->contractFormatter->formatTypeContract($event->data, 'Broadcast payload');

        if ($payload !== null) {
            $documentation[] = $payload;
        }

        return $this->eventDocumentationCache[$cacheKey] = $documentation;
    }

    /**
     * @return list<string>
     */
    private function payloadDocumentation(BroadcastEvent $event): array
    {
        $cacheKey = $event->className . "\0" . $event->name;

        if (array_key_exists($cacheKey, $this->payloadDocumentationCache)) {
            return $this->payloadDocumentationCache[$cacheKey];
        }

        $documentation = ['Broadcast payload contract'];
        $payload = $this->contractFormatter->formatTypeContract($event->data, 'Broadcast payload');

        if ($payload !== null) {
            $documentation[] = $payload;
        }

        return $this->payloadDocumentationCache[$cacheKey] = $documentation;
    }

    private function broadcastPayloadLine(ReflectionClass $reflection): int
    {
        if ($reflection->hasMethod('broadcastWith')) {
            try {
                return $reflection->getMethod('broadcastWith')->getStartLine();
            } catch (ReflectionException) {
                return $reflection->getStartLine();
            }
        }

        return $reflection->getStartLine();
    }
}

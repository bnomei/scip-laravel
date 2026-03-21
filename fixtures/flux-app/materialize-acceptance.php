<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php materialize-acceptance.php /path/to/flux-app\n");
    exit(1);
}

$targetDir = $argv[1];
$targetDir = realpath($targetDir) ?: $targetDir;
$stubsRoot = __DIR__ . '/acceptance-stubs/files';

if (! is_dir($targetDir)) {
    fwrite(STDERR, "Flux app directory not found: {$targetDir}\n");
    exit(1);
}

if (! is_dir($stubsRoot)) {
    fwrite(STDERR, "Acceptance stubs directory not found: {$stubsRoot}\n");
    exit(1);
}

copyTree($stubsRoot, $targetDir);
patchBootstrapApp($targetDir . '/bootstrap/app.php');
patchBootstrapProviders($targetDir . '/bootstrap/providers.php');
patchRoutesWeb($targetDir . '/routes/web.php');

$buildDir = $targetDir . '/build';

if (! is_dir($buildDir) && ! mkdir($buildDir, 0777, true) && ! is_dir($buildDir)) {
    fwrite(STDERR, "Could not create build directory: {$buildDir}\n");
    exit(1);
}

if (file_put_contents($targetDir . '/.scip-laravel-acceptance-ready', "ready\n") === false) {
    fwrite(STDERR, "Could not write readiness marker in {$targetDir}\n");
    exit(1);
}

echo "materialized\n";

function copyTree(string $sourceRoot, string $targetRoot): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $sourcePath = $file->getPathname();
        $relativePath = substr($sourcePath, strlen($sourceRoot) + 1);
        $destinationPath = $targetRoot . DIRECTORY_SEPARATOR . $relativePath;
        $destinationDir = dirname($destinationPath);

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0777, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException("Could not create directory: {$destinationDir}");
        }

        $contents = file_get_contents($sourcePath);

        if (! is_string($contents)) {
            throw new RuntimeException("Could not read stub file: {$sourcePath}");
        }

        if (file_put_contents($destinationPath, $contents) === false) {
            throw new RuntimeException("Could not write fixture file: {$destinationPath}");
        }
    }
}

function patchBootstrapApp(string $bootstrapPath): void
{
    $contents = file_get_contents($bootstrapPath);

    if (! is_string($contents)) {
        throw new RuntimeException("Could not read {$bootstrapPath}");
    }

    $channelsLine = "        channels: __DIR__.'/../routes/channels.php',\n";

    if (str_contains($contents, $channelsLine)) {
        return;
    }

    $needle = "        commands: __DIR__.'/../routes/console.php',\n";

    if (! str_contains($contents, $needle)) {
        throw new RuntimeException("Could not find routing marker in {$bootstrapPath}");
    }

    $patched = str_replace(
        $needle,
        $needle . $channelsLine,
        $contents,
    );

    if (file_put_contents($bootstrapPath, $patched) === false) {
        throw new RuntimeException("Could not patch {$bootstrapPath}");
    }
}

function patchRoutesWeb(string $routesPath): void
{
    $contents = file_get_contents($routesPath);

    if (! is_string($contents)) {
        throw new RuntimeException("Could not read {$routesPath}");
    }

    $legacyPattern = '/\n\/\/ scip-laravel acceptance:start.*?\/\/ scip-laravel acceptance:end\n?/s';
    $contents = preg_replace($legacyPattern, "\n", $contents) ?? $contents;
    $requireMarker = "// scip-laravel acceptance:require\nrequire __DIR__ . '/acceptance.php';\n";

    if (! str_contains($contents, $requireMarker)) {
        $contents = rtrim($contents) . "\n\n" . $requireMarker;
    }

    if (file_put_contents($routesPath, $contents) === false) {
        throw new RuntimeException("Could not patch {$routesPath}");
    }
}

function patchBootstrapProviders(string $providersPath): void
{
    $contents = file_get_contents($providersPath);

    if (! is_string($contents)) {
        throw new RuntimeException("Could not read {$providersPath}");
    }

    $provider = "    App\\Providers\\AcceptanceGraphServiceProvider::class,\n";

    if (str_contains($contents, $provider)) {
        return;
    }

    $needle = "    App\\Providers\\VoltServiceProvider::class,\n";

    if (! str_contains($contents, $needle)) {
        throw new RuntimeException("Could not find providers marker in {$providersPath}");
    }

    $patched = str_replace(
        $needle,
        $needle . $provider,
        $contents,
    );

    if (file_put_contents($providersPath, $patched) === false) {
        throw new RuntimeException("Could not patch {$providersPath}");
    }
}

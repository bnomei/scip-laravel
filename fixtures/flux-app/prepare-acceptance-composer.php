<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php prepare-acceptance-composer.php /path/to/composer.json\n");
    exit(1);
}

$path = $argv[1];

if (! is_file($path)) {
    fwrite(STDERR, "Composer file not found: {$path}\n");
    exit(1);
}

$contents = file_get_contents($path);

if ($contents === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(1);
}

$json = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

if (! is_array($json)) {
    fwrite(STDERR, "Invalid composer.json payload in {$path}\n");
    exit(1);
}

$changed = false;
$require = $json['require'] ?? [];

if (! is_array($require)) {
    fwrite(STDERR, "Expected require section in {$path}\n");
    exit(1);
}

if (($require['inertiajs/inertia-laravel'] ?? null) !== '^2.0') {
    $require['inertiajs/inertia-laravel'] = '^2.0';
    $changed = true;
}

ksort($require);
$json['require'] = $require;

$encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (! is_string($encoded)) {
    fwrite(STDERR, "Could not encode normalized acceptance composer.json\n");
    exit(1);
}

if ($changed && file_put_contents($path, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Could not write updated composer.json to {$path}\n");
    exit(1);
}

fwrite(STDOUT, $changed ? "changed" : "unchanged");

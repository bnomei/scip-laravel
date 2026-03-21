<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php normalize-composer.php /path/to/composer.json\n");
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

unset($json['repositories']);
unset($json['require-dev']);

$require = $json['require'] ?? [];

if (! is_array($require)) {
    fwrite(STDERR, "Expected require section in {$path}\n");
    exit(1);
}

unset($require['livewire/flux-pro']);
$require['livewire/flux'] = '^1.0.2';
$require['nikic/php-parser'] = '^5.7';
ksort($require);

$json['require'] = $require;

$encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if (! is_string($encoded)) {
    fwrite(STDERR, "Could not encode normalized composer.json\n");
    exit(1);
}

if (file_put_contents($path, $encoded . PHP_EOL) === false) {
    fwrite(STDERR, "Could not write normalized composer.json to {$path}\n");
    exit(1);
}

<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Symbols;

use InvalidArgumentException;

use function preg_match;
use function str_replace;
use function trim;

final readonly class SyntheticSymbolNormalizer
{
    private const string SCHEME = 'laravel';

    public function __construct(
        private ProjectSymbolPackage $package,
    ) {}

    public function route(string $name): string
    {
        return $this->domainSymbol('routes', $name);
    }

    public function routeParameter(string $routeName, string $parameterName): string
    {
        return $this->domainSymbol('route-parameter', $routeName . ':' . $parameterName);
    }

    public function view(string $name): string
    {
        return $this->domainSymbol('views', $name);
    }

    public function inertia(string $name): string
    {
        return $this->domainSymbol('inertia', $name);
    }

    public function config(string $key): string
    {
        return $this->domainSymbol('config', $key);
    }

    public function translation(string $key): string
    {
        return $this->domainSymbol('trans', $key);
    }

    public function env(string $name): string
    {
        return $this->domainSymbol('env', $name);
    }

    public function validationKey(string $key): string
    {
        return $this->domainSymbol('validation', $key);
    }

    public function routeResponse(string $routeName): string
    {
        return $this->domainSymbol('route-response', $routeName);
    }

    public function routeValidator(string $routeName): string
    {
        return $this->domainSymbol('route-validator', $routeName);
    }

    public function broadcastChannel(string $name): string
    {
        return $this->domainSymbol('broadcast-channel', $name);
    }

    public function broadcastPayload(string $name): string
    {
        return $this->domainSymbol('broadcast-payload', $name);
    }

    public function domainSymbol(string $domain, string $name): string
    {
        return (
            self::SCHEME
            . ' '
            . $this->escapePackagePart($this->package->manager)
            . ' '
            . $this->escapePackagePart($this->package->name)
            . ' '
            . $this->escapePackagePart($this->package->version)
            . ' '
            . $this->descriptor($domain, '/')
            . $this->descriptor($name, '.')
        );
    }

    private function descriptor(string $value, string $suffix): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Synthetic symbol parts must not be empty.');
        }

        if (preg_match('/^[_+\-$A-Za-z0-9]+$/', $value) === 1) {
            return $value . $suffix;
        }

        return '`' . str_replace('`', '``', $value) . '`' . $suffix;
    }

    private function escapePackagePart(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Synthetic symbol package parts must not be empty.');
        }

        return str_replace(' ', '  ', $value);
    }
}

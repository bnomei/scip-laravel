<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Symbols;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Scip\SymbolInformation;
use Scip\SymbolInformation\Kind;

use function basename;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function trim;

final class FrameworkExternalSymbolFactory
{
    private const string SCHEME = 'laravel';

    public function vendorBladeComponent(string $packageName, string $tag): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol($packageName, 'blade-component', $tag),
            'display_name' => $tag,
            'kind' => Kind::File,
            'documentation' => ['Vendor Blade component from ' . $packageName . ': ' . $tag],
        ]);
    }

    public function livewireEvent(string $eventName): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol('livewire/livewire', 'livewire-event', $eventName),
            'display_name' => $eventName,
            'kind' => Kind::Event,
            'documentation' => ['Livewire event: ' . $eventName],
        ]);
    }

    public function livewireUiDirective(string $directive): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol('livewire/livewire', 'livewire-ui', $directive),
            'display_name' => $directive,
            'kind' => Kind::Key,
            'documentation' => ['Livewire UI directive: ' . $directive],
        ]);
    }

    public function componentAttributeBag(): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol(
                'laravel/framework',
                'blade-contract',
                'Illuminate\\View\\ComponentAttributeBag',
            ),
            'display_name' => 'ComponentAttributeBag',
            'kind' => Kind::PBClass,
            'documentation' => ['Laravel Blade attribute bag: Illuminate\\View\\ComponentAttributeBag'],
        ]);
    }

    public function contextualAttribute(string $domain, string $name): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol('laravel/framework', 'container-context', $domain . ':' . $name),
            'display_name' => $name,
            'kind' => Kind::Key,
            'documentation' => ['Laravel container contextual attribute: ' . $domain . ' => ' . $name],
        ]);
    }

    public function middlewareAlias(string $alias): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol('laravel/framework', 'middleware-alias', $alias),
            'display_name' => $alias,
            'kind' => Kind::Key,
            'documentation' => ['Laravel middleware alias: ' . $alias],
        ]);
    }

    public function authorizationAbility(string $ability): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol('laravel/framework', 'authorization-ability', $ability),
            'display_name' => $ability,
            'kind' => Kind::Key,
            'documentation' => ['Laravel authorization ability: ' . $ability],
        ]);
    }

    public function phpClass(string $className): SymbolInformation
    {
        return new SymbolInformation([
            'symbol' => $this->packageDomainSymbol($this->packageNameForClass($className), 'php-class', $className),
            'display_name' => $this->classDisplayName($className),
            'kind' => Kind::PBClass,
            'documentation' => ['External PHP class: ' . $className],
        ]);
    }

    private function packageDomainSymbol(string $packageName, string $domain, string $name): string
    {
        return (
            self::SCHEME
            . ' '
            . $this->escapePackagePart('composer')
            . ' '
            . $this->escapePackagePart($packageName)
            . ' '
            . $this->escapePackagePart($this->packageVersion($packageName))
            . ' '
            . $this->descriptor($domain, '/')
            . $this->descriptor($name, '.')
        );
    }

    private function packageVersion(string $packageName): string
    {
        return (
            InstalledVersions::getReference($packageName) ?? InstalledVersions::getPrettyVersion($packageName)
            ?? 'unknown'
        );
    }

    private function packageNameForClass(string $className): string
    {
        return match (true) {
            str_starts_with($className, 'Illuminate\\'),
            str_starts_with($className, 'Laravel\\'),
                => 'laravel/framework',
            str_starts_with($className, 'Livewire\\') => 'livewire/livewire',
            str_starts_with($className, 'Inertia\\') => 'inertiajs/inertia-laravel',
            default => 'external/vendor',
        };
    }

    private function classDisplayName(string $className): string
    {
        if (!str_contains($className, '\\')) {
            return trim($className);
        }

        $offset = strrpos($className, '\\');

        return $offset === false ? trim($className) : basename(str_replace('\\', '/', $className));
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

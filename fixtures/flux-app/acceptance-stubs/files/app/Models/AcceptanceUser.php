<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AcceptanceUser extends Model
{
    public const DEFAULT_LABEL = 'acceptance-user';

    protected $guarded = [];

    public string $nickname = 'acceptance-user';

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value, array $attributes): string => trim(
                (($attributes['first_name'] ?? '') . ' ' . ($attributes['last_name'] ?? ''))
            ),
            set: static fn (string $value): array => [
                'first_name' => $value,
                'last_name' => 'Acceptance',
            ],
        );
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(AcceptanceProfile::class, 'user_id');
    }

    public function declaredSummary(): string
    {
        return $this->nickname;
    }

    public static function declaredSlug(): string
    {
        return self::DEFAULT_LABEL;
    }

    public function internalDeclaredPropertyRead(): string
    {
        return $this->nickname;
    }

    public function internalDeclaredMethodRead(): string
    {
        return $this->declaredSummary();
    }

    public function internalSelfStaticMethodRead(): string
    {
        return self::declaredSlug();
    }

    public function internalLateStaticMethodRead(): string
    {
        return static::declaredSlug();
    }

    public function internalSelfConstantRead(): string
    {
        return self::DEFAULT_LABEL;
    }

    public function internalLateStaticConstantRead(): string
    {
        return static::DEFAULT_LABEL;
    }
}

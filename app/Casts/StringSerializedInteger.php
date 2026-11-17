<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\Numeric;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An integer in PHP that serializes as a string, so a 64-bit id survives JavaScript's 2^53 number limit.
 *
 * @implements CastsAttributes<int|null, int|string|null>
 */
final class StringSerializedInteger implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : Numeric::int($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : Numeric::int($value);
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) Numeric::int($value);
    }
}

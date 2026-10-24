<?php

namespace App\Casts;

use App\Support\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Decimal quantity column read as an int when whole (2) and a float otherwise (1.5). */
class QuantityCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Quantity::from($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : Quantity::from($value);
    }
}

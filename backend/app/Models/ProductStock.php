<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Stock of one size in one option (e.g. M in Red); variant_id null = that option in every size. */
class ProductStock extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['merchant_id', 'product_id', 'variant_id', 'modifier_id', 'stock_quantity'];

    protected function casts(): array
    {
        return ['stock_quantity' => QuantityCast::class];
    }
}

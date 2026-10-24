<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'quantity',
        'unit',
        'unit_price',
        'item_total',
        'modifiers',
        'modifier_ids',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_price' => 'decimal:2',
            'item_total' => 'decimal:2',
            'modifiers' => 'array',
            'modifier_ids' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

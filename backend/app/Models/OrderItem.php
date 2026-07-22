<?php

namespace App\Models;

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
        'product_name',
        'quantity',
        'unit_price',
        'item_total',
        'modifiers',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'item_total' => 'decimal:2',
            'modifiers' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

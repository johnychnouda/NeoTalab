<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'delivery_fee',
        'minimum_order',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}

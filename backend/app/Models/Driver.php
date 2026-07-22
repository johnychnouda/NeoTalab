<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'phone',
        'whatsapp_number',
        'password',
        'status',
        'availability',
        'cash_on_hand',
        'total_deliveries',
        'active_order_id',
        'is_active',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'cash_on_hand' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}

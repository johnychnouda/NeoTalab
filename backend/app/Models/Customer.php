<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'phone',
        'name',
        'locale',
        'is_blocked',
        'block_reason',
        'total_orders',
        'total_spent',
        'last_order_at',
        'bot_paused_until',
        'addresses',
    ];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'addresses' => 'array',
            'total_spent' => 'decimal:2',
            'last_order_at' => 'datetime',
            'bot_paused_until' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}

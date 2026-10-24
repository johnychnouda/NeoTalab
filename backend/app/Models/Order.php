<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'number',
        'customer_id',
        'conversation_id',
        'driver_id',
        'delivery_zone_id',
        'delivery_zone_name',
        'delivery_address',
        'notes',
        'delivery_lat',
        'delivery_lng',
        'payment_currency',
        'exchange_rate',
        'total_lbp',
        'cash_main',
        'late_notified_at',
        'rating_requested_at',
        'rating',
        'rated_at',
        'status',
        'fulfillment',
        'subtotal',
        'delivery_fee',
        'total',
        'payment_method',
        'payment_status',
        'paid_at',
        'whish_external_id',
        'whish_collect_url',
        'reject_reason',
        'cancel_reason',
        'cancelled_by',
        'eta_mins',
        'promised_at',
        'deliver_at',
        'recipient_name',
        'recipient_phone',
        'gift_message',
        'travel_mins',
        'eta_updates',
        'due_reminded_at',
        'accepted_at',
        'preparing_at',
        'ready_at',
        'picked_up_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_lat' => 'float',
            'delivery_lng' => 'float',
            'exchange_rate' => 'decimal:2',
            'late_notified_at' => 'datetime',
            'promised_at' => 'datetime',
            'deliver_at' => 'datetime',
            'due_reminded_at' => 'datetime',
            'rating_requested_at' => 'datetime',
            'rated_at' => 'datetime',
            'accepted_at' => 'datetime',
            'paid_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}

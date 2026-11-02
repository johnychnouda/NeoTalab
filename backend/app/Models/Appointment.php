<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'product_id',
        'provider_id',
        'service_name',
        'price',
        'deposit_amount',
        'deposit_code',
        'deposit_paid_at',
        'starts_at',
        'ends_at',
        'status',
        'cancelled_by',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_paid_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** The statuses that hold a time on the calendar: a deposit not yet confirmed still holds its slot. */
    public const HOLDING = ['booked', 'pending_deposit', 'deposit_check'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'provider_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

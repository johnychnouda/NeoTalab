<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One NeoTalab subscription payment from a shop, confirmed by the platform owner against the
 * NeoTalab Whish account. Written once; the reference is unique.
 */
class SubscriptionPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'merchant_id', 'whish_reference', 'amount', 'currency', 'cycle',
        'period_start', 'period_end', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}

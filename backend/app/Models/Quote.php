<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A price the shop sends for a job the customer described (repairs, custom work). */
class Quote extends Model
{
    use BelongsToTenant, HasUuids;

    /** A price the customer has not answered stops being valid after this. */
    public const VALID_DAYS = 7;

    protected $fillable = ['merchant_id', 'number', 'customer_id', 'description', 'title', 'price', 'status', 'order_id', 'sent_at'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'number' => 'integer', 'sent_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

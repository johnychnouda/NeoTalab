<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Conversation\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'customer_id',
        'status',
        'channel',
        'state',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'state' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }
}

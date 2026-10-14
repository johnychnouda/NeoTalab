<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Conversation\CartStatus;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'conversation_id',
        'status',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'subtotal' => 'decimal:2',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /** Most recent delivery addresses offered at the next checkout. */
    public const MAX_SAVED_ADDRESSES = 5;

    /**
     * Puts a delivery address first in the saved list (an identical one moves up instead of repeating).
     *
     * @param  array{lat: float, lng: float}|array<string, mixed>|null  $location
     */
    public function rememberAddress(string $zone, string $address, ?array $location = null): void
    {
        if (trim($zone) === '' && trim($address) === '' && ! $location) {
            return;
        }
        $entry = ['id' => Str::lower(Str::random(6)), 'zone' => $zone, 'address' => $address, 'location' => $location];
        $key = fn ($a) => mb_strtolower(trim(($a['zone'] ?? '').'|'.($a['address'] ?? '')));

        $saved = collect($this->addresses ?? [])->reject(fn ($a) => $key($a) === $key($entry))->values()->all();
        array_unshift($saved, $entry);
        $this->forceFill(['addresses' => array_slice($saved, 0, self::MAX_SAVED_ADDRESSES)])->save();
    }
}

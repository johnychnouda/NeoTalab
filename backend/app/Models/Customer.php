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
        'approvals',
    ];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'addresses' => 'array',
            'approvals' => 'array',
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

    /** Approval lasts a day: long enough to order, short enough that an old check is done again. */
    public const APPROVAL_HOURS = 24;

    /** Whether the team approved this item for this customer (age checked). */
    public function isApproved(string $productId): bool
    {
        $value = ($this->approvals ?? [])[$productId] ?? null;

        return is_string($value) && $value !== 'pending' && now()->lt($value);
    }

    public function hasPendingCheck(): ?string
    {
        foreach ($this->approvals ?? [] as $productId => $value) {
            if ($value === 'pending') {
                return (string) $productId;
            }
        }

        return null;
    }

    public function markCheck(string $productId, bool $approved): void
    {
        $approvals = $this->approvals ?? [];
        $approvals[$productId] = $approved ? now()->addHours(self::APPROVAL_HOURS)->toIso8601String() : 'pending';
        $this->forceFill(['approvals' => $approvals])->save();
    }

    /** One approval, one order: used up once an order with the item is placed. */
    public function useApproval(string $productId): void
    {
        $approvals = $this->approvals ?? [];
        unset($approvals[$productId]);
        $this->forceFill(['approvals' => $approvals ?: null])->save();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\ZoneArea;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'location',
        'area',
        'color',
        'delivery_fee',
        'minimum_order',
        'delivery_mins',
        'sort_order',
        'is_active',
    ];

    /**
     * The shop's zone a customer's location pin falls in (the smallest one when zones overlap),
     * or null when it is outside every zone drawn on the map. Uses the current shop's zones.
     */
    public static function at(float $lat, float $lng): ?self
    {
        return self::query()->where('is_active', true)->whereNotNull('area')->get()
            ->filter(fn (self $zone) => ZoneArea::contains($zone->area, $lat, $lng))
            ->sortBy(fn (self $zone) => ZoneArea::size($zone->area))
            ->first();
    }

    /** True when the shop drew at least one zone on the map (customers are then matched by their pin). */
    public static function mapped(): bool
    {
        return self::query()->where('is_active', true)->whereNotNull('area')->exists();
    }

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'is_active' => 'boolean',
            'area' => 'array',
        ];
    }
}

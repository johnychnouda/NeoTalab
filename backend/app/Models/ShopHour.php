<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ShopHour extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /**
     * A shop that never saved its hours is open every day between these: what the dashboard shows
     * is what the bot does (orders, bookings, the AI's answers).
     */
    public const DEFAULT_OPEN = '09:00';

    public const DEFAULT_CLOSE = '23:00';

    protected $fillable = [
        'merchant_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
    ];

    /**
     * The current shop's week keyed by day_of_week (0 = Sunday), with the default hours when none were saved.
     *
     * @return Collection<int, ShopHour>
     */
    public static function week(): Collection
    {
        $hours = static::query()->orderBy('day_of_week')->get();

        return ($hours->isEmpty() ? collect(static::defaults()) : $hours)->keyBy('day_of_week');
    }

    /**
     * @return list<ShopHour> seven unsaved rows, Sunday first
     */
    public static function defaults(?string $merchantId = null): array
    {
        return array_map(fn (int $day) => new static([
            'merchant_id' => $merchantId,
            'day_of_week' => $day,
            'opens_at' => self::DEFAULT_OPEN,
            'closes_at' => self::DEFAULT_CLOSE,
            'is_closed' => false,
        ]), range(0, 6));
    }

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
        ];
    }
}

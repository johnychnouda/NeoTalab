<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton platform configuration (row id = 1).
 */
class PlatformSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $primaryKey = 'id';

    protected $fillable = [
        'platform_name',
        'currency',
        'timezone',
        'trial_days',
        'grace_period_days',
        'subscription_price',
        'subscription_yearly_price',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'trial_days' => 'integer',
            'grace_period_days' => 'integer',
            'subscription_price' => 'decimal:2',
            'subscription_yearly_price' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    public static function singleton(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}

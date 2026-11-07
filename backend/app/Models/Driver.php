<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'role',
        'locale',
        'phone',
        'whatsapp_number',
        'password',
        'status',
        'shift_started_at',
        'last_assigned_at',
        'availability',
        'cash_on_hand',
        'total_deliveries',
        'active_order_id',
        'is_active',
    ];

    protected $hidden = ['password'];

    /** The language this person reads WhatsApp in: their own choice, else the shop's. */
    public function languageFor(Merchant $merchant): string
    {
        return in_array($this->locale, ['en', 'ar', 'fr'], true) ? $this->locale : ($merchant->locale ?? 'en');
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'cash_on_hand' => 'decimal:2',
            'is_active' => 'boolean',
            'shift_started_at' => 'datetime',
            'last_assigned_at' => 'datetime',
        ];
    }
}

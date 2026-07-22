<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'shop_name',
        'contact_name',
        'whatsapp',
        'email',
        'business_type',
        'country',
        'street',
        'city',
        'region',
        'message',
        'status',
        'merchant_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }
}

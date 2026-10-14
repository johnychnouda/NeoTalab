<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'merchant_id',
        'wa_message_id',
        'direction',
        'from_number',
        'to_number',
        'message_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}

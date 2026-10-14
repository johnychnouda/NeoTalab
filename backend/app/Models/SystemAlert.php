<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform problem for the owner (see SystemAlertService). Not tenant-scoped.
 */
class SystemAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'level', 'message', 'fingerprint', 'context', 'merchant_id', 'occurrences',
        'first_seen_at', 'last_seen_at', 'notified_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}

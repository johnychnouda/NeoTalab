<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMemory extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'customer_id',
        'scope',
        'source',
        'content',
        'hits',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ModifierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Modifier extends Model
{
    /** @use HasFactory<ModifierFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'modifier_group_id',
        'name',
        'name_ar',
        'name_fr',
        'price_delta',
        'is_active',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}

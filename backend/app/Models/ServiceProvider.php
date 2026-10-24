<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A person customers book with (stylist, barber, doctor), with their own calendar. */
class ServiceProvider extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'merchant_id',
        'name',
        'service_ids',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'service_ids' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function offers(Product $service): bool
    {
        return $this->service_ids === null || $this->service_ids === [] || in_array($service->id, $this->service_ids, true);
    }
}

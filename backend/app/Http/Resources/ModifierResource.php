<?php

namespace App\Http\Resources;

use App\Models\Modifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Modifier */
class ModifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'modifier_group_id' => $this->modifier_group_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_fr' => $this->name_fr,
            'price_delta' => $this->price_delta,
            'is_active' => $this->is_active,
            'is_available' => $this->is_available,
            'sort_order' => $this->sort_order,
        ];
    }
}

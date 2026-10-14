<?php

namespace App\Http\Resources;

use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModifierGroup */
class ModifierGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_fr' => $this->name_fr,
            'required' => $this->required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'sort_order' => $this->sort_order,
            'modifiers' => ModifierResource::collection($this->whenLoaded('modifiers')),
        ];
    }
}

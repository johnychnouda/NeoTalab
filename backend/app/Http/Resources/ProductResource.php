<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_fr' => $this->name_fr,
            'description' => $this->description,
            'sku' => $this->sku,
            'image_url' => $this->image_url,
            'price' => $this->price,
            'is_active' => $this->is_active,
            'is_available' => $this->is_available,
            'track_inventory' => $this->track_inventory,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_orderable' => $this->isOrderable(),
            'is_low_stock' => $this->isLowStock(),
            'prep_time_mins' => $this->prep_time_mins,
            'sort_order' => $this->sort_order,
            // Explicit accessor: the column is named `attributes`, which shadows
            // Eloquent's internal property — don't rely on magic property access here.
            'attributes' => $this->resource->getAttribute('attributes'),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'modifier_groups' => ModifierGroupResource::collection($this->whenLoaded('modifierGroups')),
            'created_at' => $this->created_at,
        ];
    }
}

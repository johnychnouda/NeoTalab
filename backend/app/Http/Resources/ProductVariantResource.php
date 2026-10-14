<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_fr' => $this->name_fr,
            'sku' => $this->sku,
            'price' => $this->price,
            'is_active' => $this->is_active,
            'is_available' => $this->is_available,
            'stock_quantity' => $this->stock_quantity,
            'sort_order' => $this->sort_order,
        ];
    }
}

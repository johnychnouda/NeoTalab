<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cart */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

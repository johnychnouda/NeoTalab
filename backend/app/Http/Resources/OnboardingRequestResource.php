<?php

namespace App\Http\Resources;

use App\Models\OnboardingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OnboardingRequest */
class OnboardingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_name' => $this->shop_name,
            'contact_name' => $this->contact_name,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'business_type' => $this->business_type,
            'country' => $this->country,
            'street' => $this->street,
            'city' => $this->city,
            'region' => $this->region,
            'message' => $this->message,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}

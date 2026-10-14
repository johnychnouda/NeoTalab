<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Merchant */
class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_type' => $this->business_type,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'name_fr' => $this->name_fr,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            'address' => $this->address,
            'status' => $this->status,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'plan' => $this->plan,
            'billing_cycle' => $this->billing_cycle,
            'trial_ends_at' => $this->trial_ends_at,
            'subscription_ends_at' => $this->subscription_ends_at,
            'settings' => $this->settings,
            'created_at' => $this->created_at,
        ];
    }
}

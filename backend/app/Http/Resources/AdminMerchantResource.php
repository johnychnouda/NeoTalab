<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use App\Support\MerchantProfileSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-portal merchant shape (legacy field names) derived from the tenant row + settings JSON.
 *
 * @mixin Merchant
 */
class AdminMerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? [];
        $bot = $settings['bot'] ?? [];

        $subscriptionStatus = $settings['subscription_status']
            ?? ($this->plan === 'trial' || $this->trial_ends_at?->isFuture() ? 'trial' : ($this->status === 'active' ? 'paid' : 'pending'));

        return [
            'id' => $this->id,
            'shop_name' => $this->name,
            'name' => $this->name,
            'slug' => $this->slug,
            'business_type' => $this->business_type,
            'email' => $this->email,
            'whatsapp_number' => MerchantProfileSync::whatsapp($this->resource),
            'status' => $this->status,
            'plan' => $this->plan,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'billing_cycle' => $this->billing_cycle ?? 'monthly',
            'trial_ends_at' => $this->trial_ends_at,
            'subscription_ends' => $this->subscription_ends_at,
            'subscription_ends_at' => $this->subscription_ends_at,
            'subscription_status' => $subscriptionStatus,
            'monthly_fee' => (float) ($settings['monthly_fee'] ?? 0),
            'yearly_fee' => (float) ($settings['yearly_fee'] ?? 0),
            'last_payment_at' => $settings['last_payment_at'] ?? null,
            'city' => $settings['city'] ?? null,
            'region' => $settings['region'] ?? null,
            'street' => $settings['street'] ?? null,
            'owner_notes' => $settings['owner_notes'] ?? null,
            'bot_status' => $bot['status'] ?? 'inactive',
            'bot_phone_id' => $bot['phone_id'] ?? null,
            'bot_display_phone' => $bot['display_phone_number'] ?? null,
            'bot_waba_id' => $bot['waba_id'] ?? null,
            'bot_connected_via' => $bot['connected_via'] ?? null,
            'bot_token' => isset($bot['access_token']) ? '••••••••' : null,
            'bot_token_expires' => $bot['token_expires'] ?? null,
            'bot_last_checked' => $bot['last_checked'] ?? null,
            'bot_error' => $bot['error'] ?? null,
            'bot_mode' => $settings['mode'] ?? $bot['mode'] ?? 'auto',
            'created_at' => $this->created_at,
        ];
    }
}

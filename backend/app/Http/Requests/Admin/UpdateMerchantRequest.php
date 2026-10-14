<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,suspended,cancelled,pending'],
            'plan' => ['sometimes', 'string', 'max:30'],
            'billingCycle' => ['sometimes', 'string', 'in:monthly,yearly'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
            'monthlyFee' => ['sometimes', 'numeric', 'min:0'],
            'monthly_fee' => ['sometimes', 'numeric', 'min:0'],
            'yearlyFee' => ['sometimes', 'numeric', 'min:0'],
            'yearly_fee' => ['sometimes', 'numeric', 'min:0'],
            'subscriptionStatus' => ['sometimes', 'string', 'in:paid,trial,pending'],
            'subscription_status' => ['sometimes', 'string', 'in:paid,trial,pending'],
            'lastPaymentAt' => ['sometimes', 'nullable', 'date'],
            'last_payment_at' => ['sometimes', 'nullable', 'date'],
            'trialEndsAt' => ['sometimes', 'nullable', 'date'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'shopName' => ['sometimes', 'string', 'max:150'],
            'shop_name' => ['sometimes', 'string', 'max:150'],
            'shopNameAr' => ['sometimes', 'nullable', 'string', 'max:150'],
            'shop_name_ar' => ['sometimes', 'nullable', 'string', 'max:150'],
            'whatsappNumber' => ['sometimes', 'string', 'max:30'],
            'whatsapp_number' => ['sometimes', 'string', 'max:30'],
            'opsWhatsapp' => ['sometimes', 'string', 'max:30'],
            'ops_whatsapp' => ['sometimes', 'string', 'max:30'],
            'defaultLocale' => ['sometimes', 'string', 'max:5'],
            'default_locale' => ['sometimes', 'string', 'max:5'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'ownerNotes' => ['sometimes', 'nullable', 'string'],
            'owner_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Owner portal "Add Merchant" modal — accepts legacy camelCase fields. */
class OwnerStoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shopName' => ['required_without:shop_name', 'string', 'max:150'],
            'shop_name' => ['required_without:shopName', 'string', 'max:150'],
            'whatsappNumber' => ['required_without:whatsapp_number', 'string', 'max:30'],
            'whatsapp_number' => ['required_without:whatsappNumber', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:64'],
            'businessType' => ['nullable', 'string', 'max:60'],
            'street' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],
            'subscriptionStatus' => ['nullable', 'string', 'in:paid,trial,pending'],
            'trialEndsAt' => ['nullable', 'date'],
            'forcePasswordChange' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\BusinessKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:merchant-owner,merchant-admin
    }

    public function rules(): array
    {
        $merchantId = $this->user()?->merchant_id;

        return [
            'business_type' => ['sometimes', 'string', 'max:60', BusinessKind::notBlocked()],
            'name' => ['sometimes', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_fr' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => [
                'nullable', 'string', 'max:30',
                Rule::unique('merchants', 'whatsapp_number')->ignore($merchantId),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string', 'max:60'],
            // No 'settings': shop settings change only through their own endpoints (/settings/*), never
            // wholesale here, or a shop could repoint its WhatsApp phone ID at another shop's number.
        ];
    }
}

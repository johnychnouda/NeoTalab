<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:platform-super-admin
    }

    public function rules(): array
    {
        return [
            'business_type' => ['nullable', 'string', 'max:60'],
            'business_name' => ['required', 'string', 'max:150'],
            'locale' => ['nullable', 'string', 'max:5'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'whatsapp_number' => ['nullable', 'string', 'max:30', 'unique:merchants,whatsapp_number'],

            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
        ];
    }

    /** @return array{merchant:array,owner:array} */
    public function provisioning(): array
    {
        return [
            'merchant' => [
                'business_type' => $this->input('business_type', 'general'),
                'name' => $this->input('business_name'),
                'locale' => $this->input('locale'),
                'currency' => $this->input('currency'),
                'timezone' => $this->input('timezone'),
                'whatsapp_number' => $this->input('whatsapp_number'),
            ],
            'owner' => [
                'name' => $this->input('owner_name'),
                'email' => $this->input('owner_email'),
                'password' => $this->input('owner_password'),
            ],
        ];
    }
}

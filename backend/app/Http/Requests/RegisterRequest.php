<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public self-service signup
    }

    public function rules(): array
    {
        return [
            // Business (tenant)
            'business_type' => ['nullable', 'string', 'max:60'],
            'business_name' => ['required', 'string', 'max:150'],
            'locale' => ['nullable', 'string', 'max:5'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'whatsapp_number' => ['nullable', 'string', 'max:30', 'unique:merchants,whatsapp_number'],

            // Owner (user)
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:30'],
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
                'phone' => $this->input('phone'),
            ],
            'owner' => [
                'name' => $this->input('owner_name'),
                'email' => $this->input('email'),
                'password' => $this->input('password'),
                'phone' => $this->input('phone'),
            ],
        ];
    }
}

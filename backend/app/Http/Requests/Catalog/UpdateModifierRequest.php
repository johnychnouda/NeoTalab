<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:merchant-owner,merchant-admin
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_fr' => ['nullable', 'string', 'max:150'],
            'price_delta' => ['sometimes', 'numeric'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

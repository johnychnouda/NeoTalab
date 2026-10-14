<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:merchant-owner,merchant-admin
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_fr' => ['nullable', 'string', 'max:150'],
            'required' => ['sometimes', 'boolean'],
            'min_select' => ['sometimes', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'integer', 'min:1', 'gte:min_select'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

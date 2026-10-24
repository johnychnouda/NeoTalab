<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:merchant-owner,merchant-admin
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! Category::whereKey($value)->exists()) {
                    $fail('The selected category does not exist.');
                }
            }],
            'name' => ['sometimes', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_fr' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->where('merchant_id', $this->user()->merchant_id)
                    ->ignore($this->route('product')),
            ],
            'image_url' => ['nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string', 'max:20'],
            'offer_percent' => ['nullable', 'integer', 'min:1', 'max:90'],
            'offer_starts_on' => ['nullable', 'date'],
            'offer_ends_on' => ['nullable', 'date', 'after_or_equal:offer_starts_on'],
            'duration_mins' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'staff_check' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'prep_time_mins' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'attributes' => ['nullable', 'array'],
        ];
    }
}

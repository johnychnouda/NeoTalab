<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phoneNumberId' => ['required', 'string', 'max:80'],
            'accessToken' => ['required', 'string', 'max:500'],
            'tokenExpires' => ['nullable', 'date'],
        ];
    }
}

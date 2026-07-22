<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendMerchantWelcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp' => ['nullable', 'string', 'size:6'],
            'loginEmail' => ['nullable', 'email'],
            'regenerateAccess' => ['nullable', 'boolean'],
        ];
    }
}

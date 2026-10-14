<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetInitialPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8'],
            'newPassword' => ['sometimes', 'string', 'min:8'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('newPassword') && ! $this->filled('password')) {
            $this->merge(['password' => $this->input('newPassword')]);
        }
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('acceptedTerms')) {
            $merge['accepted_terms'] = filter_var($this->input('acceptedTerms'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->has('companyWebsite')) {
            $merge['company_website'] = $this->input('companyWebsite');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'shopName' => ['required_without:shop_name', 'string', 'max:150'],
            'shop_name' => ['required_without:shopName', 'string', 'max:150'],
            'contactName' => ['required_without:contact_name', 'string', 'max:120'],
            'contact_name' => ['required_without:contactName', 'string', 'max:120'],
            'businessType' => ['required_without:business_type', 'string', 'max:60'],
            'business_type' => ['required_without:businessType', 'string', 'max:60'],
            'country' => ['required', 'string', 'size:2'],
            'whatsapp' => ['required', 'string', 'max:30', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'city' => ['required', 'string', 'max:80'],
            'street' => ['required', 'string', 'max:190'],
            'region' => ['required', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'acceptedTerms' => ['required', 'accepted'],
            'accepted_terms' => ['required_without:acceptedTerms', 'accepted'],
            'companyWebsite' => ['nullable', 'prohibited'],
            'company_website' => ['nullable', 'prohibited'],
        ];
    }
}

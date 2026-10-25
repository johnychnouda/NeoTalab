<?php

namespace App\Http\Requests;

use App\Support\BusinessKind;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        if ($this->has('country')) {
            $merge['country'] = strtoupper((string) $this->input('country'));
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
            'businessType' => ['required_without:business_type', 'string', 'max:60', BusinessKind::notBlocked()],
            'business_type' => ['required_without:businessType', 'string', 'max:60', BusinessKind::notBlocked()],
            'country' => ['required', 'string', Rule::in(Countries::codes())],
            'whatsapp' => ['required', 'string', 'max:30', 'regex:/^\+[1-9]\d{6,14}$/', function (string $attribute, mixed $value, \Closure $fail) {
                if (in_array($this->input('country'), Countries::codes(), true) && ! Countries::numberMatches($this->input('country'), (string) $value)) {
                    $fail('The WhatsApp number must be from the chosen country.');
                }
            }],
            'email' => ['nullable', 'email', 'max:190'],
            'location' => ['required_without:street', 'nullable', 'string', 'max:500'],
            'city' => ['required_without:location', 'nullable', 'string', 'max:80'],
            'street' => ['required_without:location', 'nullable', 'string', 'max:190'],
            'region' => ['required_without:location', 'nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'acceptedTerms' => ['required', 'accepted'],
            'accepted_terms' => ['required_without:acceptedTerms', 'accepted'],
            'companyWebsite' => ['nullable', 'prohibited'],
            'company_website' => ['nullable', 'prohibited'],
        ];
    }
}

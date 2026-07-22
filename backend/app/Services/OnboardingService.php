<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function __construct(
        protected AdminMerchantService $adminMerchants,
    ) {}

    /**
     * @return list<OnboardingRequest>
     */
    public function pending(): array
    {
        return OnboardingRequest::query()
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->all();
    }

    public function reject(string $id, User $reviewer): void
    {
        $request = OnboardingRequest::query()->findOrFail($id);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['id' => ['Request is no longer pending.']]);
        }

        $request->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{merchant:Merchant,request:OnboardingRequest,user:User}
     */
    public function approve(string $id, User $reviewer, array $payload): array
    {
        $request = OnboardingRequest::query()->findOrFail($id);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['id' => ['Request is no longer pending.']]);
        }

        return DB::transaction(function () use ($request, $reviewer, $payload) {
            ['merchant' => $merchant, 'user' => $user] = $this->adminMerchants->provisionFromOwnerPortal([
                'shopName' => $request->shop_name,
                'whatsappNumber' => $request->whatsapp,
                'password' => $payload['password'] ?? '',
                'businessType' => $request->business_type,
                'country' => $request->country,
                'street' => $request->street,
                'city' => $request->city,
                'region' => $request->region,
                'subscriptionStatus' => $payload['subscriptionStatus'] ?? $payload['subscription_status'] ?? 'pending',
                'trialEndsAt' => $payload['trialEndsAt'] ?? $payload['trial_ends_at'] ?? null,
                'forcePasswordChange' => $payload['forcePasswordChange'] ?? true,
            ]);

            $request->forceFill([
                'status' => 'approved',
                'merchant_id' => $merchant->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return ['merchant' => $merchant, 'request' => $request, 'user' => $user];
        });
    }

    /**
     * Public join form submission (no auth).
     *
     * @param  array<string,mixed>  $data
     */
    public function submit(array $data): OnboardingRequest
    {
        $whatsapp = trim((string) ($data['whatsapp'] ?? $data['whatsappNumber'] ?? ''));

        if ($whatsapp !== '' && OnboardingRequest::query()->where('whatsapp', $whatsapp)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'whatsapp' => ['An application with this WhatsApp number is already pending review.'],
            ]);
        }

        return OnboardingRequest::create([
            'shop_name' => trim((string) ($data['shopName'] ?? $data['shop_name'] ?? '')),
            'contact_name' => $data['contactName'] ?? $data['contact_name'] ?? null,
            'whatsapp' => trim((string) ($data['whatsapp'] ?? $data['whatsappNumber'] ?? '')),
            'email' => $data['email'] ?? null,
            'business_type' => $data['businessType'] ?? $data['business_type'] ?? null,
            'country' => strtoupper((string) ($data['country'] ?? '')),
            'street' => $data['street'] ?? null,
            'city' => $data['city'] ?? null,
            'region' => $data['region'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);
    }
}

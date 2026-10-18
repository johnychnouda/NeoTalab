<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\MapLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function __construct(
        protected AdminMerchantService $adminMerchants,
        protected SystemAlertService $alerts,
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
                'location' => $request->location,
                'subscriptionStatus' => $payload['subscriptionStatus'] ?? $payload['subscription_status'] ?? 'pending',
                // A trial lasts the number of days set in the owner portal (Settings → Pricing).
                'trialEndsAt' => $payload['trialEndsAt'] ?? $payload['trial_ends_at']
                    ?? (($payload['subscriptionStatus'] ?? $payload['subscription_status'] ?? null) === 'trial'
                        ? now()->addDays(max(1, (int) (PlatformSetting::singleton()->trial_days ?: 3)))->toIso8601String() : null),
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

        $location = filled($data['location'] ?? null)
            ? (MapLocation::resolve((string) $data['location']) ?? throw ValidationException::withMessages([
                'location' => [__('No location found in this link. In Google Maps, press and hold on your shop to drop a pin, then paste the link or the numbers (e.g. 33.8938, 35.5018).')],
            ]))
            : null;

        $request = OnboardingRequest::create([
            'shop_name' => trim((string) ($data['shopName'] ?? $data['shop_name'] ?? '')),
            'contact_name' => $data['contactName'] ?? $data['contact_name'] ?? null,
            'whatsapp' => trim((string) ($data['whatsapp'] ?? $data['whatsappNumber'] ?? '')),
            'email' => $data['email'] ?? null,
            'business_type' => $data['businessType'] ?? $data['business_type'] ?? null,
            'country' => strtoupper((string) ($data['country'] ?? '')),
            'location' => $location,
            'street' => $data['street'] ?? null,
            'city' => $data['city'] ?? null,
            'region' => $data['region'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);

        $this->notifyOwner($request);

        return $request;
    }

    /** Emails / WhatsApps the owner (Settings → Alerts recipients) that a shop wants to join. */
    protected function notifyOwner(OnboardingRequest $request): void
    {
        // Someone flooding the public form from many IPs must not flood the owner's inbox too.
        if (! RateLimiter::attempt('join-request-notify', 20, fn () => true, 3600)) {
            return;
        }

        $area = implode(', ', array_filter([$request->city, $request->region]));
        $link = rtrim((string) config('app.frontend_url'), '/').'/owner?req='.$request->id;
        $lines = array_filter([
            'Shop: '.$request->shop_name,
            $request->business_type ? 'Type: '.$request->business_type : null,
            $area !== '' ? 'Area: '.$area : null,
            'Contact: '.implode(' · ', array_filter([$request->contact_name, $request->whatsapp, $request->email])),
        ]);

        // One tap from the email or the WhatsApp alert to the request in Owner portal → Merchants.
        $email = "A new shop wants to join NeoTalab.\n\n".implode("\n", $lines)."\n\nReview it: {$link}";
        $whatsapp = 'New shop request: '.$request->shop_name
            .(($about = implode(', ', array_filter([$request->business_type, $area]))) !== '' ? " ({$about})" : '')
            .'. Contact: '.implode(' ', array_filter([$request->contact_name, $request->whatsapp]))
            .". Review it: {$link}";

        $this->alerts->notice($email, 'New shop request: '.$request->shop_name, '', $whatsapp);
    }
}

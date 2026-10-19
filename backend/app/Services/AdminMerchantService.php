<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\BusinessKind;
use App\Support\Countries;
use App\Support\MapLocation;
use App\Support\MerchantProfileSync;
use App\Support\Roles;
use App\Support\ShopSlug;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform-admin operations on merchants (billing, bot config, impersonation).
 * Extra owner-portal fields live in merchants.settings until dedicated billing/WhatsApp tables land (M4+).
 */
class AdminMerchantService
{
    public function __construct(
        protected TenantProvisioningService $provisioning,
        protected MerchantBotService $bots,
    ) {}

    public function find(string $id): Merchant
    {
        return Merchant::query()->findOrFail($id);
    }

    /**
     * @param  array<string,mixed>  $payload  Legacy owner-portal camelCase/snake_case fields.
     */
    public function update(Merchant $merchant, array $payload): Merchant
    {
        $settings = $merchant->settings ?? [];

        $this->guardPaidPeriodBillingChanges($merchant, $settings, $payload);

        if ($status = $this->pick($payload, 'status')) {
            $merchant->status = $status;
        }

        if ($plan = $this->pick($payload, 'plan')) {
            $merchant->plan = $plan;
        }

        if ($cycle = $this->pick($payload, 'billing_cycle', 'billingCycle')) {
            $merchant->billing_cycle = $cycle;
        }

        if (($trialEnds = $this->pick($payload, 'trial_ends_at', 'trialEndsAt')) !== null) {
            $merchant->trial_ends_at = $trialEnds ?: null;
        }

        if (($subEnds = $this->pick($payload, 'subscription_ends_at', 'subscriptionEnds')) !== null) {
            $merchant->subscription_ends_at = $subEnds ?: null;
        }

        $markingPaid = $this->pick($payload, 'lastPaymentAt', 'last_payment_at') !== null;

        foreach ([
            'monthly_fee' => ['monthly_fee', 'monthlyFee'],
            'yearly_fee' => ['yearly_fee', 'yearlyFee'],
            'last_payment_at' => ['last_payment_at', 'lastPaymentAt'],
            'subscription_status' => ['subscription_status', 'subscriptionStatus'],
            'city' => ['city'],
            'region' => ['region'],
            'street' => ['street'],
            'country' => ['country'],
            'owner_notes' => ['owner_notes', 'ownerNotes'],
        ] as $key => $aliases) {
            $val = $this->pick($payload, ...$aliases);
            if ($val !== null) {
                $settings[$key] = $val;
            }
        }

        if ($this->hasKey($payload, 'shopName', 'shop_name', 'shopNameAr', 'shop_name_ar')) {
            MerchantProfileSync::applyShopName(
                $merchant,
                $this->hasKey($payload, 'shopName', 'shop_name')
                    ? $this->pick($payload, 'shopName', 'shop_name')
                    : null,
                $this->hasKey($payload, 'shopNameAr', 'shop_name_ar')
                    ? $this->pick($payload, 'shopNameAr', 'shop_name_ar')
                    : null,
            );
        }
        if ($this->hasKey($payload, 'whatsappNumber', 'whatsapp_number', 'opsWhatsapp', 'ops_whatsapp')) {
            MerchantProfileSync::applyWhatsapp(
                $merchant,
                $settings,
                (string) $this->pick($payload, 'whatsappNumber', 'whatsapp_number', 'opsWhatsapp', 'ops_whatsapp'),
            );
        }
        if ($this->hasKey($payload, 'defaultLocale', 'default_locale', 'locale')) {
            MerchantProfileSync::applyLocale(
                $merchant,
                $this->pick($payload, 'defaultLocale', 'default_locale', 'locale'),
            );
        }

        // Fixing the type picked at sign-up also resets what depends on it (orders or bookings, time unit).
        $type = $this->pick($payload, 'businessType', 'business_type');
        if (is_string($type) && $type !== '' && $type !== $merchant->business_type) {
            $merchant->business_type = $type;
            unset($settings['business_kind']);
            $settings = BusinessKind::applyDefaults($settings, BusinessKind::TYPES[$type] ?? 'other');
        }

        if ($markingPaid && ($settings['subscription_status'] ?? null) === 'paid') {
            $this->appendPayment($settings, $merchant);
        }

        $merchant->settings = $settings;
        $merchant->save();

        return $merchant->fresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{merchant:Merchant,user:User}
     */
    public function provisionFromOwnerPortal(array $payload): array
    {
        $shopName = trim((string) ($payload['shopName'] ?? $payload['shop_name'] ?? ''));
        $whatsapp = trim((string) ($payload['whatsappNumber'] ?? $payload['whatsapp_number'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($shopName === '' || $whatsapp === '' || $password === '') {
            throw ValidationException::withMessages([
                'shopName' => ['Shop name, WhatsApp number, and activation code are required.'],
            ]);
        }

        if (Merchant::query()->where('whatsapp_number', $whatsapp)->exists()) {
            throw ValidationException::withMessages([
                'whatsappNumber' => ['This WhatsApp number is already registered.'],
            ]);
        }

        $email = $this->emailFromShopName($shopName);

        $subscriptionStatus = $payload['subscriptionStatus'] ?? $payload['subscription_status'] ?? 'pending';
        $trialEndsAt = $payload['trialEndsAt'] ?? $payload['trial_ends_at'] ?? null;
        $platform = PlatformSetting::singleton();

        $settings = [
            'subscription_status' => $subscriptionStatus,
            'monthly_fee' => (float) ($this->pick($payload, 'monthly_fee', 'monthlyFee') ?? $platform->subscription_price ?? 30),
            'yearly_fee' => (float) ($this->pick($payload, 'yearly_fee', 'yearlyFee') ?? $platform->subscription_yearly_price ?? 300),
            'ops_whatsapp' => $whatsapp,
            'street' => $payload['street'] ?? null,
            'city' => $payload['city'] ?? null,
            'region' => $payload['region'] ?? null,
            'country' => $payload['country'] ?? null,
            'payments' => [],
            // The join pin is where the shop delivers from and where customers pick up.
            'shop_location' => filled($payload['location'] ?? null) ? (string) $payload['location'] : null,
            'pickup_address' => MapLocation::mapsUrl((string) ($payload['location'] ?? ''))
                ?: implode(', ', array_filter([$payload['street'] ?? null, $payload['city'] ?? null])),
            // Lebanese shops take cash in lira too; the shop can change the rate or turn it off.
            'lbp_rate' => strtoupper((string) ($payload['country'] ?? '')) === 'LB' ? 89500 : null,
            'mode' => 'auto',
            'bot' => [
                'status' => 'inactive',
            ],
        ];

        return DB::transaction(function () use ($shopName, $whatsapp, $password, $payload, $subscriptionStatus, $trialEndsAt, $settings, $email) {
            ['merchant' => $merchant, 'user' => $user] = $this->provisioning->provision(
                [
                    'business_type' => $payload['businessType'] ?? $payload['business_type'] ?? 'general',
                    'name' => $shopName,
                    'whatsapp_number' => $whatsapp,
                    'phone' => $whatsapp,
                    // The shop's country decides its clock (hours, reports) and its currency.
                    'timezone' => Countries::timezone($payload['country'] ?? null),
                    'currency' => Countries::currency($payload['country'] ?? null),
                ],
                [
                    'name' => $shopName,
                    'email' => $email,
                    'password' => $password,
                    'phone' => $whatsapp,
                ],
            );

            $merchant->forceFill([
                'status' => 'active',
                'plan' => $subscriptionStatus === 'trial' ? 'trial' : 'basic',
                'trial_ends_at' => $trialEndsAt ? now()->parse($trialEndsAt) : ($subscriptionStatus === 'trial' ? now()->addDays(max(1, (int) (PlatformSetting::singleton()->trial_days ?: 3))) : null),
                'settings' => $settings,
            ])->save();

            if (! empty($payload['forcePasswordChange'])) {
                $user->forceFill(['password_changed_at' => null])->save();
            }

            return ['merchant' => $merchant->fresh(), 'user' => $user];
        });
    }

    public function delete(Merchant $merchant): void
    {
        DB::transaction(function () use ($merchant) {
            User::query()->where('merchant_id', $merchant->id)->delete();
            $merchant->delete();
        });
    }

    /**
     * @return array{token:string,merchant:Merchant,user:User}
     */
    public function impersonate(Merchant $merchant): array
    {
        /** @var User|null $owner */
        $owner = User::query()
            ->where('merchant_id', $merchant->id)
            ->whereHas('roles', fn ($q) => $q->where('name', Roles::MERCHANT_OWNER))
            ->first();

        if (! $owner) {
            $owner = User::query()->where('merchant_id', $merchant->id)->first();
        }

        if (! $owner) {
            throw ValidationException::withMessages([
                'merchant' => ['This merchant has no login account yet.'],
            ]);
        }

        // Short-lived: the owner portal only needs it for one support session.
        $token = $owner->createToken('impersonation', ['*'], now()->addHours(8))->plainTextToken;

        return ['token' => $token, 'merchant' => $merchant, 'user' => $owner];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function payments(Merchant $merchant): array
    {
        $settings = $merchant->settings ?? [];

        return array_values($settings['payments'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateBot(Merchant $merchant, array $payload): Merchant
    {
        return $this->bots->updateBot($merchant, $payload);
    }

    /**
     * @return array{success:bool,error?:string,display_phone_number?:string}
     */
    public function testBot(Merchant $merchant): array
    {
        return $this->bots->testBot($merchant);
    }

    /**
     * @return array{webhook_url:string,verify_token:string,message:string}
     */
    public function registerWebhook(Merchant $merchant): array
    {
        return $this->bots->registerWebhook($merchant);
    }

    public function restartBot(Merchant $merchant): Merchant
    {
        return $this->bots->restartBot($merchant);
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    protected function appendPayment(array &$settings, Merchant $merchant): void
    {
        $payments = $settings['payments'] ?? [];
        $amount = ($merchant->billing_cycle === 'yearly')
            ? (float) ($settings['yearly_fee'] ?? 0)
            : (float) ($settings['monthly_fee'] ?? 0);

        $payments[] = [
            'id' => (string) Str::uuid(),
            'paid_at' => $settings['last_payment_at'],
            'amount' => $amount,
            'method' => 'Manual',
            'cycle' => $merchant->billing_cycle ?? 'monthly',
        ];

        $settings['payments'] = $payments;
    }

    /**
     * During an active paid month/year, lock cycle + fee for that period.
     *
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>  $payload
     */
    protected function guardPaidPeriodBillingChanges(Merchant $merchant, array $settings, array $payload): void
    {
        if (($settings['subscription_status'] ?? null) !== 'paid') {
            return;
        }

        // Recording a new payment starts a new period, allow edits in that request.
        if ($this->pick($payload, 'lastPaymentAt', 'last_payment_at') !== null) {
            return;
        }

        $periodEnd = $this->paidPeriodEndsAt($merchant, $settings);
        if (! $periodEnd || now()->greaterThanOrEqualTo($periodEnd)) {
            return;
        }

        $cycle = $merchant->billing_cycle ?? 'monthly';
        $until = $periodEnd->toFormattedDateString();
        $errors = [];

        if ($this->hasKey($payload, 'billingCycle', 'billing_cycle')) {
            $next = (string) $this->pick($payload, 'billingCycle', 'billing_cycle');
            if ($next !== '' && $next !== $cycle) {
                $errors['billingCycle'] = ["Billing cycle is locked until the current paid period ends ({$until})."];
            }
        }

        if ($cycle === 'yearly' && $this->hasKey($payload, 'yearlyFee', 'yearly_fee')) {
            $next = (float) $this->pick($payload, 'yearlyFee', 'yearly_fee');
            $current = (float) ($settings['yearly_fee'] ?? 0);
            if (abs($next - $current) > 0.001) {
                $errors['yearlyFee'] = ["Yearly fee is locked until the current paid year ends ({$until})."];
            }
        }

        if ($cycle !== 'yearly' && $this->hasKey($payload, 'monthlyFee', 'monthly_fee')) {
            $next = (float) $this->pick($payload, 'monthlyFee', 'monthly_fee');
            $current = (float) ($settings['monthly_fee'] ?? 0);
            if (abs($next - $current) > 0.001) {
                $errors['monthlyFee'] = ["Monthly fee is locked until the current paid month ends ({$until})."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    protected function paidPeriodEndsAt(Merchant $merchant, array $settings): ?CarbonInterface
    {
        $last = $settings['last_payment_at'] ?? null;
        if (! $last) {
            return null;
        }

        $start = Carbon::parse($last)->startOfDay();

        return ($merchant->billing_cycle ?? 'monthly') === 'yearly'
            ? $start->copy()->addYear()
            : $start->copy()->addMonth();
    }

    protected function emailFromShopName(string $shopName): string
    {
        $slug = rtrim(Str::limit(ShopSlug::from($shopName), 40, ''), '-');

        if ($slug === '') {
            $slug = Str::lower(Str::random(8));
        }

        return $this->uniqueMerchantEmail($slug);
    }

    protected function uniqueMerchantEmail(string $slug): string
    {
        $base = $slug.'@merchant.neotalab';
        if (! User::query()->where('email', $base)->exists()) {
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = $slug.$i.'@merchant.neotalab';
            if (! User::query()->where('email', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $slug.Str::lower(Str::random(4)).'@merchant.neotalab';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function pick(array $payload, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function hasKey(array $payload, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }
}

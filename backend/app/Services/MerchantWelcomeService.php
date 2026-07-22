<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantProfileSync;
use App\Support\Roles;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sends merchant onboarding welcome messages from the platform WhatsApp number.
 */
class MerchantWelcomeService
{
    public function __construct(
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * Reset merchant login password to the activation code and send credentials.
     *
     * @return array{sent:bool,message:string,loginEmail:string,otp:string,welcomeError:?string}
     */
    public function resendAccess(Merchant $merchant, string $otp): array
    {
        $otp = preg_replace('/\D/', '', $otp) ?? '';
        if (strlen($otp) !== 6) {
            throw ValidationException::withMessages([
                'otp' => ['A 6-digit activation code is required.'],
            ]);
        }

        $user = $this->ownerUser($merchant);
        $user->forceFill([
            'password' => $otp,
            'password_changed_at' => null,
        ])->save();

        // Force re-login everywhere with the new temporary code.
        User::query()
            ->where('merchant_id', $merchant->id)
            ->get()
            ->each(fn (User $u) => $u->tokens()->delete());

        $loginEmail = $user->email;
        $welcomeError = null;
        $sent = false;

        try {
            $this->send($merchant, $otp, $loginEmail, 'regain');
            $sent = true;
        } catch (ValidationException $e) {
            $welcomeError = collect($e->errors())->flatten()->first() ?: $e->getMessage();
        }

        return [
            'sent' => $sent,
            'message' => $sent
                ? 'Access reset and regain-access message sent via WhatsApp.'
                : 'Access reset. Send the WhatsApp message so the merchant can regain access.',
            'loginEmail' => $loginEmail,
            'otp' => $otp,
            'welcomeError' => $welcomeError,
        ];
    }

    /**
     * @param  'welcome'|'regain'  $variant
     * @return array{sent:bool,message:string}
     */
    public function send(Merchant $merchant, ?string $otp = null, ?string $loginEmail = null, string $variant = 'welcome'): array
    {
        $to = MerchantProfileSync::whatsapp($merchant);
        if (! $to) {
            throw ValidationException::withMessages([
                'whatsapp' => ['Merchant has no WhatsApp number on file.'],
            ]);
        }

        $loginUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/')
            .'/backoffice/'.($merchant->slug ?: '');
        $loginUrl = rtrim($loginUrl, '/');
        $email = $loginEmail ?: $this->ownerUser($merchant)->email;
        $body = $variant === 'regain'
            ? $this->regainAccessBody($merchant->name, $loginUrl, $email, $otp)
            : $this->welcomeBody($merchant->name, $loginUrl, $email, $otp);

        try {
            $this->whatsapp->sendPlatform($to, [
                'type' => 'text',
                'text' => $body,
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'whatsapp' => [$e->getMessage()],
            ]);
        }

        return [
            'sent' => true,
            'message' => $variant === 'regain'
                ? 'Regain-access message sent via platform WhatsApp.'
                : 'Welcome message sent via platform WhatsApp.',
        ];
    }

    protected function welcomeBody(string $shopName, string $loginUrl, string $email, ?string $otp): string
    {
        $otpLine = $otp ? "🔐 Activation code: *{$otp}*\n" : '';

        return "Hello! 👋 Welcome to NeoTalab!\n\n"
            ."Your merchant account for *{$shopName}* is ready.\n\n"
            ."🔗 Login: {$loginUrl}\n"
            ."📧 Email: {$email}\n"
            .$otpLine
            ."Use the activation code as your first password — you'll set your own password right after.\n\n"
            ."Need help? Just reply here! 🙌";
    }

    protected function regainAccessBody(string $shopName, string $loginUrl, string $email, ?string $otp): string
    {
        $otpLine = $otp ? "🔐 Temporary access code: *{$otp}*\n" : '';

        return "Hello! 🔐 Here's how to regain access to your NeoTalab account for *{$shopName}*.\n\n"
            ."We reset your login so you can get back into the merchant portal.\n\n"
            ."🔗 Login: {$loginUrl}\n"
            ."📧 Email: {$email}\n"
            .$otpLine
            ."Sign in with this temporary code, then set a new password right away.\n\n"
            ."If you didn't ask for this, reply here and we'll help.";
    }

    public static function loginEmailFromShopName(string $shopName): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($shopName)) ?? '');
        $slug = Str::limit($slug, 40, '');

        return ($slug !== '' ? $slug : Str::lower(Str::random(8))).'@merchant.neotalab';
    }

    protected function ownerUser(Merchant $merchant): User
    {
        $user = User::query()
            ->where('merchant_id', $merchant->id)
            ->get()
            ->first(fn (User $u) => $u->hasRole(Roles::MERCHANT_OWNER))
            ?? User::query()->where('merchant_id', $merchant->id)->orderBy('created_at')->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'merchant' => ['This merchant has no login user to reset.'],
            ]);
        }

        return $user;
    }
}

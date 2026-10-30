<?php

namespace Tests\Concerns;

use App\Models\Merchant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\Roles;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait InteractsWithTenants
{
    /** The platform owner records a shop's Whish payment for NeoTalab (a unique reference unless given). */
    protected function recordWhishPayment(string $token, Merchant $merchant, ?string $reference = null): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/admin/merchants/{$merchant->id}/payments", [
            'whish_reference' => $reference ?? 'WS-'.strtoupper(Str::random(8)),
            'confirmed_check' => true,
        ]);
    }

    /**
     * Create a merchant plus one user with the given role.
     *
     * @return array{0:Merchant,1:User}
     */
    protected function merchantWithUser(string $role = Roles::MERCHANT_OWNER, array $merchantAttrs = []): array
    {
        $merchant = Merchant::factory()->create($merchantAttrs);
        $user = User::factory()->forMerchant($merchant)->create();
        $user->assignRole($role);

        return [$merchant, $user];
    }

    protected function platformAdmin(): User
    {
        $admin = User::factory()->platformAdmin()->create();
        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);

        return $admin;
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * A staff (or driver) number wrote to the shop number, it is inside WhatsApp's 24-hour window
     * from then on, so the shop can send it normal messages with buttons.
     */
    protected function staffWroteToShop(Merchant $merchant, string $phone, ?\DateTimeInterface $at = null): void
    {
        $message = WhatsAppMessage::query()->withoutGlobalScopes()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.HELLO'.uniqid(), 'direction' => 'inbound',
            'from_number' => '+'.preg_replace('/\D+/', '', $phone), 'to_number' => '555', 'message_type' => 'text', 'payload' => [],
        ]);
        if ($at) {
            $message->forceFill(['created_at' => $at])->save();
        }
    }
}

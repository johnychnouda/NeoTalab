<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;

/**
 * Changes a shop owner's login email. Run from the Railway API console. The owner keeps the same
 * password and signs in with the new email.
 */
class SetShopLoginEmail extends Command
{
    protected $signature = 'shop:set-login-email {slug : The shop\'s slug} {email : The new login email}';

    protected $description = 'Change the owner login email of a shop';

    public function handle(): int
    {
        $merchant = Merchant::where('slug', $this->argument('slug'))->first();
        if (! $merchant) {
            $this->error('No shop has that slug.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("{$email} is already used by another account.");

            return self::FAILURE;
        }

        $owner = User::query()->where('merchant_id', $merchant->id)->get()
            ->first(fn (User $u) => $u->hasRole(Roles::MERCHANT_OWNER));
        if (! $owner) {
            $this->error('This shop has no owner account.');

            return self::FAILURE;
        }

        $owner->forceFill(['email' => $email])->save();
        $this->info("{$merchant->name}: {$email}");

        return self::SUCCESS;
    }
}

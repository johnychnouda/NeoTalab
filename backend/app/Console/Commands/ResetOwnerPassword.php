<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lost owner password: run from the Railway API console. Prints a new one-time password
 * (shown only here, never logged); the owner signs in with it and changes it in Settings.
 * --new-email moves the owner login to another address (handing the platform to a new owner).
 */
class ResetOwnerPassword extends Command
{
    protected $signature = 'owner:reset-password {--email= : Owner email (default: the only platform owner)}
        {--new-email= : Change the owner login to this email}';

    protected $description = 'Give the platform owner a new password and sign out every session';

    public function handle(): int
    {
        $owners = User::role(Roles::PLATFORM_SUPER_ADMIN)
            ->when($this->option('email'), fn ($q, $email) => $q->where('email', $email))
            ->get();

        if ($owners->count() !== 1) {
            $this->error($owners->isEmpty()
                ? 'No platform owner found.'
                : 'Several owners exist; pass --email=… ('.$owners->pluck('email')->implode(', ').').');

            return self::FAILURE;
        }

        $owner = $owners->first();
        $newEmail = strtolower(trim((string) $this->option('new-email')));
        if ($newEmail !== '') {
            if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL) || User::where('email', $newEmail)->whereKeyNot($owner->getKey())->exists()) {
                $this->error("{$newEmail} is not a valid email or is already used by another account.");

                return self::FAILURE;
            }
            $owner->email = $newEmail;
        }

        $password = Str::password(16, symbols: false);
        $owner->forceFill(['password' => $password])->save();
        $owner->tokens()->delete();

        $this->info("Email:    {$owner->email}");
        $this->info("Password: {$password}");
        $this->line('Sign in at /owner, then change it in Settings → Security.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * Verify credentials and issue a Sanctum token.
     *
     * Runs before any tenant is bound, so the user lookup is intentionally unscoped.
     *
     * @return array{user:User,token:string}
     */
    public function login(string $email, string $password): array
    {
        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status === 'disabled') {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        // Shop staff work through WhatsApp only; the dashboard is for the owner and managers.
        if ($user->hasRole(Roles::MERCHANT_STAFF)) {
            throw ValidationException::withMessages([
                'email' => ['Team members work through WhatsApp. Ask your manager for help.'],
            ]);
        }

        // The activation code is a first password: valid for 24 hours, and once used the shop has one more hour to choose its own.
        if ($user->password_changed_at === null && $user->activation_expires_at !== null) {
            if ($user->activation_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'email' => ['This activation code has expired. Ask NeoTalab to send you a new one.'],
                ]);
            }
            $user->forceFill(['activation_expires_at' => $user->activation_expires_at->min(now()->addHour())])->save();
        }

        $token = $user->createToken('api')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * @return array{message:string}
     */
    public function setInitialPassword(User $user, string $password): array
    {
        if ($user->password_changed_at !== null) {
            throw ValidationException::withMessages([
                'password' => ['Use the change-password flow to update your password.'],
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
            'activation_expires_at' => null,
        ])->save();

        return ['message' => 'Password updated.'];
    }

    /**
     * @return array{token:string,password_changed_at:string}
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $newPassword,
            'password_changed_at' => now(),
            'activation_expires_at' => null,
        ])->save();

        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return [
            'token' => $token,
            'password_changed_at' => $user->password_changed_at->toIso8601String(),
        ];
    }

    /**
     * New login email (owner portal → Settings → Security). Other sessions are signed out.
     *
     * @return array{token:string,email:string}
     */
    public function changeEmail(User $user, string $currentPassword, string $email): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill(['email' => $email])->save();

        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return ['token' => $token, 'email' => $user->email];
    }

    /**
     * Revoke all tokens except the current one; optionally rotate the current token.
     *
     * @return array{token:string}
     */
    public function revokeOtherSessions(User $user, PersonalAccessToken $currentToken): array
    {
        $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        $currentToken->delete();
        $token = $user->createToken('api')->plainTextToken;

        return ['token' => $token];
    }

    /**
     * "Forgot password": emails a reset link to the account's email. Always answers the same way, so
     * nobody can find out which emails have accounts. Team members (who can't use the dashboard) get nothing.
     */
    public function sendResetLink(string $email): void
    {
        $user = User::query()->where('email', strtolower(trim($email)))->first();
        if (! $user || $user->status === 'disabled' || $user->hasRole(Roles::MERCHANT_STAFF)) {
            return;
        }

        Password::broker()->sendResetLink(['email' => $user->email]);
    }

    /**
     * Sets the new password from the emailed link and signs out every session.
     *
     * @return array{message:string}
     */
    public function resetPassword(string $email, string $token, string $password): array
    {
        $status = Password::broker()->reset(
            ['email' => strtolower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $user, string $newPassword) {
                $user->forceFill(['password' => $newPassword, 'password_changed_at' => now(), 'activation_expires_at' => null])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__('This reset link is no longer valid. Ask for a new one.')]]);
        }

        return ['message' => 'Password updated. Sign in with your new password.'];
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
        ])->save();

        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return [
            'token' => $token,
            'password_changed_at' => $user->password_changed_at->toIso8601String(),
        ];
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
}

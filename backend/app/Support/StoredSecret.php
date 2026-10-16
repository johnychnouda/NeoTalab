<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Access tokens kept in JSON settings columns are stored encrypted with APP_KEY, so a leaked
 * database dump does not leak them. Values saved before encryption was added are plain text;
 * open() still returns those as they are (the `secrets:encrypt` migration converts them).
 */
final class StoredSecret
{
    public static function seal(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : Crypt::encryptString($value);
    }

    public static function open(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($stored));
        } catch (DecryptException) {
            return $stored; // saved before encryption
        }
    }

    public static function isSealed(?string $stored): bool
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return false;
        }

        try {
            Crypt::decryptString($stored);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}

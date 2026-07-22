<?php

namespace App\Support;

/**
 * Canonical role names. A user belongs to exactly one merchant (or none, for platform
 * admins), so roles are global — spatie's "teams" feature is intentionally not used.
 */
final class Roles
{
    public const PLATFORM_SUPER_ADMIN = 'platform-super-admin';

    public const MERCHANT_OWNER = 'merchant-owner';

    public const MERCHANT_ADMIN = 'merchant-admin';

    public const MERCHANT_STAFF = 'merchant-staff';

    public const DRIVER = 'driver'; // reserved — driver auth is built in the dispatch milestone

    /** Roles that manage a merchant's own users. */
    public const MERCHANT_MANAGERS = [self::MERCHANT_OWNER, self::MERCHANT_ADMIN];

    public const ALL = [
        self::PLATFORM_SUPER_ADMIN,
        self::MERCHANT_OWNER,
        self::MERCHANT_ADMIN,
        self::MERCHANT_STAFF,
        self::DRIVER,
    ];
}

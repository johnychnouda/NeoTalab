<?php

use App\Support\StoredSecret;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts the WhatsApp access tokens that were saved in plain text before StoredSecret existed:
 * each shop's settings.bot.access_token and the platform's settings.wa_access_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')->select(['id', 'settings'])->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);
            $token = $settings['bot']['access_token'] ?? null;
            if (! is_string($token) || $token === '' || StoredSecret::isSealed($token)) {
                return;
            }
            $settings['bot']['access_token'] = StoredSecret::seal($token);
            DB::table('merchants')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        });

        DB::table('platform_settings')->select(['id', 'settings'])->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);
            $token = $settings['wa_access_token'] ?? null;
            if (! is_string($token) || $token === '' || StoredSecret::isSealed($token)) {
                return;
            }
            $settings['wa_access_token'] = StoredSecret::seal($token);
            DB::table('platform_settings')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        // Tokens stay encrypted; StoredSecret::open() reads both forms.
    }
};

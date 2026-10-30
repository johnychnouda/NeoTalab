<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Whish is paid to the shop's Whish number now (no API). The Whish channel, secret and sandbox
 * flag are no longer used, so they are removed from the shops' settings. The secret is not
 * recoverable afterwards; a shop that wants to keep Whish enters its Whish number again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true);
            if (! is_array($settings) || ! isset($settings['whish']) || ! is_array($settings['whish'])) {
                return;
            }

            unset($settings['whish']['channel'], $settings['whish']['secret'], $settings['whish']['sandbox']);
            if ($settings['whish'] === []) {
                unset($settings['whish']);
            }

            DB::table('merchants')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        });
    }

    public function down(): void
    {
        // The secrets cannot be restored: nothing to undo.
    }
};

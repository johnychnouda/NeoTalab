<?php

use App\Support\BusinessKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shops created from the owner portal or approved from /join before 23 October 2026 lost the
 * settings their business type gives (orders or bookings, time unit): salons and clinics could not
 * take bookings. This fills in only the missing ones from each shop's type; anything a shop already
 * set is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('merchants')->orderBy('id')->each(function ($row) {
            $settings = json_decode((string) $row->settings, true) ?: [];
            $kind = is_string($settings['business_kind'] ?? null) && isset(BusinessKind::KINDS[$settings['business_kind']])
                ? $settings['business_kind']
                : (BusinessKind::TYPES[(string) $row->business_type] ?? 'other');
            $defaults = BusinessKind::applyDefaults([], $kind);

            $missing = array_diff_key($defaults, array_filter($settings, fn ($v) => $v !== null && $v !== ''));
            if ($missing === []) {
                return;
            }

            DB::table('merchants')->where('id', $row->id)->update(['settings' => json_encode(array_merge($settings, $missing))]);
        });
    }

    public function down(): void
    {
        // Nothing to undo: the filled values are the ones the shops should have had.
    }
};

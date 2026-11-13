<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Remap shops that still shared restaurant/grocery kinds onto their own type-specific kind. */
    public function up(): void
    {
        $map = [
            'Bakery / Sweets' => 'bakery',
            'Butcher / Fruits & Vegetables' => 'butcher',
        ];

        foreach (DB::table('merchants')->select('id', 'business_type', 'settings')->get() as $row) {
            $type = (string) $row->business_type;
            if (! isset($map[$type])) {
                continue;
            }
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
            if (! is_array($settings)) {
                $settings = [];
            }
            $kind = $map[$type];
            if (($settings['business_kind'] ?? null) === $kind) {
                continue;
            }
            $settings['business_kind'] = $kind;
            if ($kind === 'bakery' || $kind === 'butcher') {
                $settings['timing_unit'] = $settings['timing_unit'] ?? 'minutes';
            }
            DB::table('merchants')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible remap of bakery/butcher kinds; leave as-is.
    }
};

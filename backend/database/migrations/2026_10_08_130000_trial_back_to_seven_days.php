<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The free trial stays at 7 days (the 14-day launch value is undone; prices are unchanged). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(7)->change();
        });

        DB::table('platform_settings')->where('trial_days', 14)->update(['trial_days' => 7]);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(14)->change();
        });
    }
};

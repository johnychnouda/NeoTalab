<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The free trial is 3 days with every feature of a paid shop (replaces the 7-day default). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(3)->change();
        });

        DB::table('platform_settings')->where('trial_days', 7)->update(['trial_days' => 3]);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(7)->change();
        });
    }
};

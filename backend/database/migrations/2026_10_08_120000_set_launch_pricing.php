<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Launch pricing: $30/month, $300/year, 14-day free trial. Only replaces the old defaults
 * ($29 / $290 / 7 days), a price the owner already changed in Settings is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(14)->change();
            $table->decimal('subscription_price', 10, 2)->default(30)->change();
            $table->decimal('subscription_yearly_price', 10, 2)->default(300)->change();
        });

        DB::table('platform_settings')->where('subscription_price', 29)->where('subscription_yearly_price', 290)
            ->update(['subscription_price' => 30, 'subscription_yearly_price' => 300]);
        DB::table('platform_settings')->where('trial_days', 7)->update(['trial_days' => 14]);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(7)->change();
            $table->decimal('subscription_price', 10, 2)->default(29)->change();
            $table->decimal('subscription_yearly_price', 10, 2)->default(290)->change();
        });
    }
};

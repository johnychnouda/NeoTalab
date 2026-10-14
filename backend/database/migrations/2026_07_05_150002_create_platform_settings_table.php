<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton (id = 1) holding platform-wide configuration owned by the platform super-admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary()->default(1);
            $table->string('platform_name')->default('NeoTalab');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('Asia/Beirut');
            $table->unsignedSmallInteger('trial_days')->default(7);
            $table->unsignedSmallInteger('grace_period_days')->default(3);
            $table->decimal('subscription_price', 10, 2)->default(29);
            $table->decimal('subscription_yearly_price', 10, 2)->default(290);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};

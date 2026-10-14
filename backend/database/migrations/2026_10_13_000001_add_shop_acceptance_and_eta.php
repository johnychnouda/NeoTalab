<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // When the customer was promised the order (arrival, pickup time, or delivery date).
            $table->timestamp('promised_at')->nullable()->after('eta_mins');
            $table->unsignedTinyInteger('eta_updates')->default(0)->after('promised_at');
            $table->timestamp('due_reminded_at')->nullable()->after('eta_updates');
        });

        Schema::table('delivery_zones', function (Blueprint $table) {
            // Driving time from the shop to this area, added to the preparation time.
            $table->unsignedSmallInteger('delivery_mins')->default(15)->after('minimum_order');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['promised_at', 'eta_updates', 'due_reminded_at']));
        Schema::table('delivery_zones', fn (Blueprint $table) => $table->dropColumn('delivery_mins'));
    }
};

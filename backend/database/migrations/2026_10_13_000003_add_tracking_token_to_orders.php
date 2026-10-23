<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unguessable id for the customer's public order-tracking page (/t/{token}).
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_token', 32)->nullable()->unique()->after('number');
        });
    }

    public function down(): void
    {
        // Already gone when 2026_10_14_000001 (which removed tracking) was not rolled back with it.
        if (! Schema::hasColumn('orders', 'tracking_token')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn('tracking_token');
        });
    }
};

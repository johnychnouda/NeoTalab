<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // NeoTalab works entirely inside WhatsApp: no menu page carts, no tracking links.
    public function up(): void
    {
        Schema::dropIfExists('menu_carts');
        if (Schema::hasColumn('orders', 'tracking_token')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique(['tracking_token']);
                $table->dropColumn('tracking_token');
            });
        }
    }

    public function down(): void
    {
        //
    }
};

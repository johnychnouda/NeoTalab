<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NeoTalab only takes orders now: bookings, stylists, deposits and price quotes are gone for good. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('service_providers');
        Schema::dropIfExists('quotes');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('duration_mins');
        });
    }

    public function down(): void
    {
        // Nothing to restore.
    }
};

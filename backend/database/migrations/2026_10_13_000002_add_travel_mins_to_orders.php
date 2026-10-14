<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Driving time worked out once per order (Google Maps or the area's time) and reused.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('travel_mins')->nullable()->after('promised_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('travel_mins'));
    }
};

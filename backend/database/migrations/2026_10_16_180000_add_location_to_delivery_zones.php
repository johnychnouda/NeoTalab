<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            // "lat,lng" of this area on Google Maps. Driving time is calculated from the shop pin.
            $table->string('location', 40)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_zones', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};

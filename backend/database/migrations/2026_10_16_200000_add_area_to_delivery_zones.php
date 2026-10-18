<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_zones', function (Blueprint $table) {
            // The zone drawn on Google Maps (see App\Support\ZoneArea): a circle around a point or a
            // shape. A customer's location pin is matched to the zone it falls in.
            $table->json('area')->nullable()->after('location');
            $table->string('color', 9)->nullable()->after('area');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_zones', fn (Blueprint $table) => $table->dropColumn(['area', 'color']));
    }
};

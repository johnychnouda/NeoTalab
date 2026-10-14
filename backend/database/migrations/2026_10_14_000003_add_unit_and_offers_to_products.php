<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // How the item is sold: kg, piece, gallon… (shown next to the price); null = per item.
            $table->string('unit', 20)->nullable()->after('price');
            // Offer: a percentage off, optionally limited to dates (shop's local dates).
            $table->unsignedTinyInteger('offer_percent')->nullable()->after('unit');
            $table->date('offer_starts_on')->nullable()->after('offer_percent');
            $table->date('offer_ends_on')->nullable()->after('offer_starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['unit', 'offer_percent', 'offer_starts_on', 'offer_ends_on']));
    }
};

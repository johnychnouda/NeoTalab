<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shops confirm availability themselves when they accept an order, so NeoTalab no longer counts stock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_stocks');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['track_inventory', 'stock_quantity', 'low_stock_threshold']);
        });
    }

    public function down(): void
    {
        // Stock counting is gone for good; nothing to restore.
    }
};

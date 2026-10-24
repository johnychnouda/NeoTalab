<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clothing and shoes: stock for one size in one colour (or one option alone when the item has
     * no sizes). A size's own total stays on product_variants.stock_quantity.
     */
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('variant_id')->nullable();
            $table->uuid('modifier_id');
            $table->decimal('stock_quantity', 10, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'variant_id', 'modifier_id']);
        });
        // The chosen options' ids, so a cancelled order gives that size-and-colour stock back.
        Schema::table('order_items', fn (Blueprint $table) => $table->json('modifier_ids')->nullable()->after('modifiers'));
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('modifier_ids'));
        Schema::dropIfExists('product_stocks');
    }
};

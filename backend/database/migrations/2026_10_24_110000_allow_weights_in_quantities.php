<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Items sold by weight: 1.5 kg in a cart, an order and in stock. */
    public function up(): void
    {
        Schema::table('cart_items', fn (Blueprint $table) => $table->decimal('quantity', 8, 3)->default(1)->change());
        Schema::table('order_items', fn (Blueprint $table) => $table->decimal('quantity', 8, 3)->default(1)->change());
        // "kg" on a weighed line, so tickets and receipts say "1.5 kg Tomatoes", not "1.5× Tomatoes".
        Schema::table('order_items', fn (Blueprint $table) => $table->string('unit', 20)->nullable()->after('quantity'));
        Schema::table('products', fn (Blueprint $table) => $table->decimal('stock_quantity', 10, 3)->nullable()->change());
        Schema::table('product_variants', fn (Blueprint $table) => $table->decimal('stock_quantity', 10, 3)->nullable()->change());
    }

    /** Back to whole numbers: a weight like 1.5 is cut to 1. */
    public function down(): void
    {
        Schema::table('cart_items', fn (Blueprint $table) => $table->unsignedSmallInteger('quantity')->default(1)->change());
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('unit'));
        Schema::table('order_items', fn (Blueprint $table) => $table->unsignedSmallInteger('quantity')->default(1)->change());
        Schema::table('products', fn (Blueprint $table) => $table->unsignedInteger('stock_quantity')->nullable()->change());
        Schema::table('product_variants', fn (Blueprint $table) => $table->unsignedInteger('stock_quantity')->nullable()->change());
    }
};

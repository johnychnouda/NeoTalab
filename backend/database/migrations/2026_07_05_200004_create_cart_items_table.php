<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items in a cart, resolved against the tenant catalog. modifier_ids stores an
 * array of modifier UUIDs; prices are snapshotted at add-time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('cart_id')->index();
            $table->uuid('product_id')->index();
            $table->uuid('variant_id')->nullable()->index();

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->json('modifier_ids')->nullable();
            $table->decimal('line_total', 10, 2);
            $table->string('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};

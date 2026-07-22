<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product variants — mutually exclusive versions of a product (Small/Medium/Large,
 * 250mg/500mg, Red/Blue). `price` is the absolute price of that variant; when NULL the
 * variant inherits the product price. Carries merchant_id (defense in depth: every
 * tenant-scoped table has its own tenant key, never derived through a join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('product_id')->index();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('sku')->nullable();

            $table->decimal('price', 10, 2)->nullable(); // NULL → inherit product price

            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('stock_quantity')->nullable(); // used when product tracks inventory
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['merchant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};

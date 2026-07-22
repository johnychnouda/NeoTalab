<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products. Tenant-scoped, business-type agnostic. Inventory columns support the two modes
 * merchants actually use: a simple availability toggle (is_available — "sold out today"),
 * and optional counted stock (track_inventory + stock_quantity + low_stock_threshold).
 * Stock decrementing happens in the orders milestone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('category_id')->nullable()->index();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('image_url')->nullable();

            $table->decimal('price', 10, 2); // currency lives on the merchant

            // Availability & inventory
            $table->boolean('is_active')->default(true);      // listed at all
            $table->boolean('is_available')->default(true);   // quick "sold out" toggle
            $table->boolean('track_inventory')->default(false);
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable();

            // Minutes needed to prepare/fulfil one unit (nullable — not all businesses prep)
            $table->unsignedSmallInteger('prep_time_mins')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            // Arbitrary business-type-specific attributes (dosage, size chart, allergens, …)
            $table->json('attributes')->nullable();

            $table->timestamps();

            // SKU unique within a tenant (multiple NULLs allowed by MySQL/SQLite)
            $table->unique(['merchant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

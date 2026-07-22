<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifiers — add-ons/options attached to a product, grouped with selection rules
 * (e.g. "Toppings: choose up to 3", "Gift wrap: optional"). Unlike variants, modifiers
 * are additive: each selected modifier applies price_delta on top of the base price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('product_id')->index();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();

            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('modifier_group_id')->index();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();

            $table->decimal('price_delta', 10, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};

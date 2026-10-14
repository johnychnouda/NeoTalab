<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent shopping cart, one open cart per conversation. Totals are recalculated
 * on every cart change; delivery fees are added at checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('conversation_id')->index();

            $table->string('status', 20)->default('open'); // open, converted, abandoned
            $table->decimal('subtotal', 10, 2)->default(0);

            $table->timestamps();

            $table->unique('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};

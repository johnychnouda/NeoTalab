<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's NeoTalab subscription payment, recorded by the platform owner after checking the
 * NeoTalab Whish account. The Whish reference is unique: one transfer can only pay once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->string('whish_reference', 40)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('cycle', 10);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->index(['merchant_id', 'confirmed_at']);
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};

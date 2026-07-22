<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End customers who order via WhatsApp (or simulation). Tenant-scoped; phone is the
 * primary identifier within a merchant. No DB-level FK on merchant_id (SQLite-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();

            $table->string('phone');
            $table->string('name')->nullable();
            $table->string('locale', 5)->default('en'); // en, ar, fr
            $table->boolean('is_blocked')->default(false);

            $table->timestamps();

            $table->unique(['merchant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cart built on the public menu page, handed to WhatsApp as a short code.
        Schema::create('menu_carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->string('code', 12);
            $table->json('items');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_carts');
    }
};

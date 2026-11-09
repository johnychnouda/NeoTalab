<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lessons the shop AI "student" keeps: shop-wide habits and per-customer preferences.
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('customer_id')->nullable()->index();
            $table->string('scope', 16); // shop | customer
            $table->string('source', 32); // rating | owner | chat | system
            $table->text('content');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
    }
};

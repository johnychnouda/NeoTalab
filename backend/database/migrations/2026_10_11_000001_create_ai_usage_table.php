<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per shop per month: what the AI assistant cost NeoTalab.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->char('month', 7); // YYYY-MM
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};

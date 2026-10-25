<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Repair shops and services: the customer describes the job, the shop sends a price, the customer accepts. */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->unsignedInteger('number');
            $table->uuid('customer_id')->index();
            $table->string('description', 1000);
            $table->string('title', 150)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status', 20)->default('requested'); // requested | sent | accepted | declined
            $table->uuid('order_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

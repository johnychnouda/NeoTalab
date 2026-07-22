<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->string('wa_message_id', 191);
            $table->string('direction', 10); // inbound | outbound
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('message_type', 30)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'wa_message_id']);
            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};

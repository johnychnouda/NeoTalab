<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI conversation sessions tied to a customer. `state` holds message history and
 * conversation-phase metadata; structured AI output is persisted on each turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('customer_id')->index();

            $table->string('status', 20)->default('active'); // active, closed
            $table->string('channel', 20)->default('simulation'); // simulation, whatsapp (M4)
            $table->json('state')->nullable(); // messages, phase, last_ai_output snapshot
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

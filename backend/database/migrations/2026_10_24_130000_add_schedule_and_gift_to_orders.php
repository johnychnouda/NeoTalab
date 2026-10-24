<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Florists, cake shops, gifts: a delivery time the customer picks, and someone else who receives it. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('deliver_at')->nullable()->after('promised_at');
            $table->string('recipient_name', 80)->nullable();
            $table->string('recipient_phone', 30)->nullable();
            $table->string('gift_message', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['deliver_at', 'recipient_name', 'recipient_phone', 'gift_message']));
    }
};

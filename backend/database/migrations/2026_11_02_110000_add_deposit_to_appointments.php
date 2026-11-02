<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // A booking that asks for a deposit holds its time as pending_deposit, then deposit_check, until the shop confirms the money.
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('price');
            $table->string('deposit_code', 8)->nullable()->after('deposit_amount'); // what the customer writes in the Whish note
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_code');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['deposit_amount', 'deposit_code', 'deposit_paid_at']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who sent the Whish transfer, as Whish shows it to the owner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->string('sender_name', 80)->nullable()->after('whish_reference');
            $table->string('sender_phone', 30)->nullable()->after('sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn(['sender_name', 'sender_phone']);
        });
    }
};

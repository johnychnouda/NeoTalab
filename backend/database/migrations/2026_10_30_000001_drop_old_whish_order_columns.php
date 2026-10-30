<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old Whish API stored its payment id and collect link on each order. Whish is paid by
 * transfer now, so nothing reads these columns. Dropping them deletes that history for good.
 * down() adds the empty columns back, but the old values cannot be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['whish_external_id']);
            $table->dropColumn(['whish_external_id', 'whish_collect_url']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('whish_external_id')->nullable()->unique();
            $table->text('whish_collect_url')->nullable();
        });
    }
};

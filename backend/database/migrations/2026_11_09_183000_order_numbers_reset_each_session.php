<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order numbers restart at 1 each trading session (after the shop closes and opens again).
 * The old unique (merchant_id, number) would block a second day's #1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'number']);
            $table->index(['merchant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'number']);
            $table->unique(['merchant_id', 'number']);
        });
    }
};

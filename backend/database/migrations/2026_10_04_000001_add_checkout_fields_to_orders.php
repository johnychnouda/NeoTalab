<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('merchant_id');
            $table->uuid('conversation_id')->nullable()->index()->after('customer_id');
            $table->uuid('delivery_zone_id')->nullable()->after('driver_id');
            $table->string('delivery_zone_name')->nullable()->after('delivery_zone_id');
            $table->text('delivery_address')->nullable()->after('delivery_zone_name');
            $table->text('notes')->nullable()->after('delivery_address');
            $table->unique(['merchant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'number']);
            $table->dropIndex(['conversation_id']);
            $table->dropColumn(['number', 'conversation_id', 'delivery_zone_id', 'delivery_zone_name', 'delivery_address', 'notes']);
        });
    }
};

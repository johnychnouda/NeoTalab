<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_address');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $table->string('payment_currency', 3)->nullable()->after('payment_method');
            $table->decimal('exchange_rate', 14, 2)->nullable()->after('payment_currency');
            $table->decimal('total_lbp', 16, 0)->nullable()->after('exchange_rate');
            $table->timestamp('late_notified_at')->nullable();
            $table->timestamp('rating_requested_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('rated_at')->nullable();
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('shift_started_at')->nullable()->after('status');
            $table->timestamp('last_assigned_at')->nullable()->after('shift_started_at');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('bot_paused_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_lat', 'delivery_lng', 'payment_currency', 'exchange_rate', 'total_lbp',
                'late_notified_at', 'rating_requested_at', 'rating', 'rated_at',
            ]);
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['shift_started_at', 'last_assigned_at']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('bot_paused_until');
        });
    }
};

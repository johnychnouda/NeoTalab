<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The customer rates three things after an order: the order (the existing rating), the service, the delivery person. 5 great, 3 okay, 1 bad. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_service')->nullable()->after('rating');
            $table->unsignedTinyInteger('rating_delivery')->nullable()->after('rating_service');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['rating_service', 'rating_delivery']));
    }
};

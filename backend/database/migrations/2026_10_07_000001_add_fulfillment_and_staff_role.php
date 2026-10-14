<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment', 20)->default('delivery')->after('status'); // delivery | pickup
        });
        Schema::table('drivers', function (Blueprint $table) {
            // driver: gets assigned deliveries. staff: gets every order with Ready/Done buttons and opens/closes the shop.
            $table->string('role', 20)->default('driver')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('fulfillment'));
        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};

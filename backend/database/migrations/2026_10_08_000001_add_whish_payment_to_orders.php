<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('cash')->after('payment_method'); // cash | pending | paid | failed
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->unsignedBigInteger('whish_external_id')->nullable()->unique();
            $table->text('whish_collect_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['whish_external_id']);
            $table->dropColumn(['payment_status', 'paid_at', 'whish_external_id', 'whish_collect_url']);
        });
    }
};

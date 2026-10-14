<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Split cash payment: the part paid in the shop currency; total_lbp then holds the LBP remainder.
            $table->decimal('cash_main', 10, 2)->nullable()->after('payment_currency');
            $table->string('cancelled_reason_code')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cash_main', 'cancelled_reason_code']);
        });
    }
};

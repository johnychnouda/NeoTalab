<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split cash saves 'MIXED' (5 letters) in a 3-letter column: MySQL refused every order paid
     * part in dollars, part in lira (SQLite ignores the length, so the tests passed there).
     */
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->string('payment_currency', 10)->nullable()->change());
    }

    /** Kept wide on rollback: narrowing it again would cut 'MIXED' off the split orders already saved. */
    public function down(): void {}
};

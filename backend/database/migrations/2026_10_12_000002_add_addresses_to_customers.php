<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved delivery addresses (most recent first, max 5), offered at checkout.
        Schema::table('customers', function (Blueprint $table) {
            $table->json('addresses')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('addresses');
        });
    }
};

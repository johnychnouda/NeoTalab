<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An item the team must check before it is sold (prescription medicine, age-limited items).
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('staff_check')->default(false)->after('is_available');
        });
        // Items the team approved for this customer: {product_id: "pending" | expiry time}.
        Schema::table('customers', function (Blueprint $table) {
            $table->json('approvals')->nullable()->after('addresses');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('staff_check'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('approvals'));
    }
};

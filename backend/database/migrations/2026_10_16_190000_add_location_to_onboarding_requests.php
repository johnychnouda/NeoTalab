<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table) {
            // "lat,lng" of the shop pin from the join form.
            $table->string('location', 40)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_requests', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};

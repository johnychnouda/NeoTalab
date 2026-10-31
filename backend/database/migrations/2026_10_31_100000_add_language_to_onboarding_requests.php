<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table) {
            // The language the applicant used on the join page (en, ar or fr): the WhatsApp answers come in it.
            $table->string('language', 5)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_requests', fn (Blueprint $table) => $table->dropColumn('language'));
    }
};

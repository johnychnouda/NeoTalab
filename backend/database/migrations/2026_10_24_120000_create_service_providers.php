<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Stylists, barbers, doctors: the people a customer can book with, each with their own calendar. */
    public function up(): void
    {
        Schema::create('service_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('name', 80);
            // Ids of the services this person does; null = every service.
            $table->json('service_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->uuid('provider_id')->nullable()->after('product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('provider_id'));
        Schema::dropIfExists('service_providers');
    }
};

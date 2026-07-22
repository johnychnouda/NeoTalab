<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * merchants = the tenant (a business). Generic across business types
 * (restaurant, pharmacy, retail, services, …) — no business-specific columns.
 * Every tenant-scoped table in later milestones carries merchant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identity — generic, business-type agnostic
            $table->string('business_type')->default('general')->index(); // restaurant | pharmacy | retail | …
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_url')->nullable();

            // Contact
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_number')->nullable()->unique();
            $table->string('address')->nullable();

            // Lifecycle
            $table->string('status')->default('pending')->index(); // pending | active | suspended | cancelled

            // Localization / regional
            $table->string('locale', 5)->default('en');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('Asia/Beirut');

            // Subscription (full billing is a later milestone; these are the minimum needed now)
            $table->string('plan')->default('basic');           // basic | pro | enterprise
            $table->string('billing_cycle')->default('monthly'); // monthly | yearly
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_starts_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            // Arbitrary per-tenant configuration (feature flags, AI settings, etc.)
            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};

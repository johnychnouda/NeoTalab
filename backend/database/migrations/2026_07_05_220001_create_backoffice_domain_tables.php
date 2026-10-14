<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant backoffice domain: onboarding, settings, drivers, orders.
 * All tenant-scoped tables carry merchant_id; no DB-level FKs (SQLite-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('shop_name');
            $table->string('contact_name')->nullable();
            $table->string('whatsapp');
            $table->string('email')->nullable();
            $table->string('business_type')->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pending')->index(); // pending | approved | rejected
            $table->uuid('merchant_id')->nullable()->index();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday … 6=Saturday
            $table->string('opens_at', 8)->default('09:00');
            $table->string('closes_at', 8)->default('23:00');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['merchant_id', 'day_of_week']);
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('name');
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('minimum_order', 10, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->string('name');
            $table->string('phone');
            $table->string('whatsapp_number')->nullable();
            $table->string('password');
            $table->string('status')->default('off_duty'); // on_duty | off_duty | on_break | paused
            $table->string('availability')->default('available'); // available | busy
            $table->decimal('cash_on_hand', 10, 2)->default(0);
            $table->unsignedInteger('total_deliveries')->default(0);
            $table->uuid('active_order_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['merchant_id', 'phone']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('customer_id')->index();
            $table->uuid('driver_id')->nullable()->index();
            $table->string('status')->default('pending_payment')->index();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('payment_method')->default('cash'); // cash | wish
            $table->string('reject_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->unsignedSmallInteger('eta_mins')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('order_id')->index();
            $table->string('product_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('item_total', 10, 2)->default(0);
            $table->json('modifiers')->nullable();
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('block_reason')->nullable()->after('is_blocked');
            $table->unsignedInteger('total_orders')->default(0)->after('block_reason');
            $table->decimal('total_spent', 12, 2)->default(0)->after('total_orders');
            $table->timestamp('last_order_at')->nullable()->after('total_spent');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['block_reason', 'total_orders', 'total_spent', 'last_order_at']);
        });
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('shop_hours');
        Schema::dropIfExists('onboarding_requests');
    }
};

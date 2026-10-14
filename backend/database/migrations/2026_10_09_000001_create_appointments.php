<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Set → the product is a bookable service of this length (salons, clinics, …).
            $table->unsignedSmallInteger('duration_mins')->nullable()->after('price');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('customer_id')->index();
            $table->uuid('product_id')->nullable();
            $table->string('service_name');
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('booked'); // booked | cancelled | completed | no_show
            $table->string('cancelled_by', 20)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'starts_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('duration_mins'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the customer wrote (or said) after an Okay or Bad rating: the shop reads it and answers. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('rating_issue')->nullable()->after('rated_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('rating_issue');
        });
    }
};

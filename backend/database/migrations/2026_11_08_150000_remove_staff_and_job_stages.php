<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The team is the delivery drivers only: the shop answers from its own phone. Job stages went with the staff buttons. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('drivers')->where('role', 'staff')->delete();
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('job_stage');
        });
    }

    public function down(): void
    {
        // Nothing to restore.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level problems (failed backups, WhatsApp/AI errors…) shown in the owner Inbox.
        // Not tenant-scoped: merchant_id only says which shop it was about, when known.
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level', 16);              // warning | error | critical
            $table->string('message', 500);
            $table->string('fingerprint', 64)->index(); // same problem → one row with a count
            $table->json('context')->nullable();
            $table->char('merchant_id', 36)->nullable()->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};

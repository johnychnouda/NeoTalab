<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // No sold-out / hide switches and no stock counting: every item on the menu is shown.
    // To take an item off the menu, the shop deletes it.
    public function up(): void
    {
        DB::table('products')->update(['is_active' => true, 'is_available' => true, 'track_inventory' => false]);
    }

    public function down(): void
    {
        //
    }
};

<?php
// Scale lab: every shop open 24/7 with one delivery zone ("Hamra", 2.00, no minimum). Scale lab only.
require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;
if (! str_contains(config('database.connections.mysql.database'), 'neotalab_qa_scale')) { exit("refusing\n"); }
$now = now()->toDateTimeString(); $n = 0;
foreach (DB::table('merchants')->where('slug', 'like', 'scale-%')->pluck('id') as $mid) {
    DB::table('shop_hours')->where('merchant_id', $mid)->delete(); DB::table('delivery_zones')->where('merchant_id', $mid)->delete();
    for ($d = 0; $d < 7; $d++) { DB::table('shop_hours')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $mid, 'day_of_week' => $d, 'opens_at' => '00:00', 'closes_at' => '23:59', 'is_closed' => 0, 'created_at' => $now, 'updated_at' => $now]); }
    DB::table('delivery_zones')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $mid, 'name' => 'Hamra', 'delivery_fee' => 2.00, 'minimum_order' => 0, 'delivery_mins' => 30, 'sort_order' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $n++;
}
echo "$n shops\n";

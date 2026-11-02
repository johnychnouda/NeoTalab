<?php
// Scale lab: one API token per shop owner (so the tests need no 120 logins). Written to the git-ignored lab folder. Scale lab only.
require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! str_contains(config('database.connections.mysql.database'), 'neotalab_qa_scale')) { exit("refusing\n"); }
$out = [];
foreach (App\Models\Merchant::withoutGlobalScopes()->where('slug', 'like', 'scale-%')->get() as $m) {
    $u = App\Models\User::withoutGlobalScopes()->where('merchant_id', $m->id)->first();
    $out[$m->id] = $u->createToken('scale')->plainTextToken;
}
file_put_contents(__DIR__.'/.lab/scale/tokens.json', json_encode($out));
chmod(__DIR__.'/.lab/scale/tokens.json', 0600);
echo count($out)."\n";

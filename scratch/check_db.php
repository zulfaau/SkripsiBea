<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$fts = DB::table('scholarships')->where('id', 8)->value('fts_content');
echo "FTS Content for ID 8:\n";
var_dump($fts);

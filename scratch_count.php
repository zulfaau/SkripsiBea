<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$res = \Illuminate\Support\Facades\DB::table('scholarships')
    ->where('jenjang', 'like', '%S1%')
    ->where('deadline', 'like', '%oktober%')
    ->where('negara', 'not like', '%indonesia%')
    ->count();

echo "Count S1, oktober, luar: " . $res . "\n";

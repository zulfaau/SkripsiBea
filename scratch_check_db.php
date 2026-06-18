<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$count = DB::table('scholarships')->count();
echo "Total Count: " . $count . "\n";

$rows = DB::table('scholarships')->select('id', 'nama_beasiswa', 'negara', 'deadline')->take(20)->get();
foreach ($rows as $r) {
    echo "ID: {$r->id} - {$r->nama_beasiswa} - Country: {$r->negara} - Deadline: {$r->deadline}\n";
}

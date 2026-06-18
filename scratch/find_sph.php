<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$count = DB::table('scholarships')->where('nama_beasiswa', 'like', '%Sph Media%')->count();
echo "Count for Sph Media: " . $count . "\n";

$rows = DB::table('scholarships')->where('nama_beasiswa', 'like', '%Sph Media%')->get();
foreach ($rows as $r) {
    echo "ID: {$r->id} - Name: {$r->nama_beasiswa} - Negara: {$r->negara}\n";
}

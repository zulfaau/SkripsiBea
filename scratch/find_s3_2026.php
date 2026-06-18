<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$count = DB::table('scholarships')
    ->where('jenjang', 'like', '%S3%')
    ->where(function($q) {
        $q->where('deadline', 'like', '%2026%')
          ->orWhere('nama_beasiswa', 'like', '%2026%');
    })
    ->count();
echo "Count of S3 scholarships for 2026: " . $count . "\n";

$rows = DB::table('scholarships')
    ->where('jenjang', 'like', '%S3%')
    ->where(function($q) {
        $q->where('deadline', 'like', '%2026%')
          ->orWhere('nama_beasiswa', 'like', '%2026%');
    })
    ->select('id', 'nama_beasiswa', 'jenjang', 'deadline')
    ->take(10)
    ->get();

foreach ($rows as $r) {
    echo "ID: {$r->id} - Name: {$r->nama_beasiswa} - Jenjang: {$r->jenjang} - Deadline: {$r->deadline}\n";
}

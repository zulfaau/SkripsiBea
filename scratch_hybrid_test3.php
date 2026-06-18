<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\ChatbotController::class);
$reflector = new ReflectionClass($controller);
$methodEmbed = $reflector->getMethod('generateEmbedding');
$methodEmbed->setAccessible(true);
$embedding = $methodEmbed->invoke($controller, 'beasiswa s1 luar negeri tutup bulan oktober');
$searchIds = \Illuminate\Support\Facades\DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    'beasiswa s1 luar negeri tutup bulan oktober', '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);
echo "ID 6 in top 100: " . (in_array(6, $ids) ? 'YES' : 'NO') . "\n";
echo "ID 7 in top 100: " . (in_array(7, $ids) ? 'YES' : 'NO') . "\n";

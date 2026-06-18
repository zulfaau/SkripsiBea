<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = 'kalau beasiswa yang masih buka di dalam negeri ada apa';
$criteria = [
    'negara' => ['indonesia'],
    'benua' => [],
    'jenjang' => [],
    'bulan' => [],
    'tahun' => [],
    'semester' => null,
    'funding' => null,
    'negara_ori' => null,
    'bidang' => [],
    'lokasi_tipe' => 'dalam',
    'still_open' => true,
    'clean_subject_words' => ['apa']
];

$vStart = microtime(true);
$embedding = $reflector->getMethod('generateEmbedding')->invoke($controller, 'beasiswa indonesia');
$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    'beasiswa indonesia', '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);
echo "Total IDs found: " . count($ids) . "\n";

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

echo "Total raw results from DB: " . count($rawResults) . "\n";

$applyStrictFilters = $reflector->getMethod('applyStrictFilters');
$applyStrictFilters->setAccessible(true);

$filtered = $applyStrictFilters->invoke($controller, $rawResults, $criteria);
echo "Filtered count: " . count($filtered) . "\n";

// Let's print out what failed the filters
foreach ($rawResults as $r) {
    $negaraLower = strtolower($r->negara ?? '');
    $isActuallyDalam = str_contains($negaraLower, 'indonesia');
    $deadlineTime = $reflector->getMethod('parseDeadlineDate')->invoke($controller, $r->deadline ?? '');
    $isExpired = ($deadlineTime !== null && $deadlineTime < time());
    echo "ID: {$r->id}, Name: {$r->nama_beasiswa}, Negara: {$r->negara}, Tipe: " . ($isActuallyDalam ? 'dalam' : 'luar') . ", Expired: " . ($isExpired ? 'YES' : 'NO') . " (Deadline: {$r->deadline})\n";
}

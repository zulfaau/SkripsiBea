<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "kalau beasiswa yg masi buka di dn ada apa aja";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

if (preg_match('/\b(paling dekat|deadline dekat|mepet|terdekat|tercepat)\b/i', $normalizedText)) {
    $criteria['sort_deadline'] = true;
    $criteria['sort_deadline_dir'] = 'asc';
}

echo "Normalized Message: $normalizedText\n";
echo "Criteria: " . json_encode($criteria, JSON_PRETTY_PRINT) . "\n";

// Now run the hybrid search
$generateEmbedding = $reflector->getMethod('generateEmbedding');
$generateEmbedding->setAccessible(true);
$embedding = $generateEmbedding->invoke($controller, $normalizedText);

$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $normalizedText, '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

echo "Raw results count: " . count($rawResults) . "\n";

// Let's trace why each raw result is filtered out
foreach ($rawResults as $r) {
    // Check filter conditions manually
    $reasons = [];
    
    // Tipe Lokasi filter
    if (!empty($criteria['lokasi_tipe'])) {
        $negaraLower = strtolower($r->negara ?? '');
        $isActuallyDalam = str_contains($negaraLower, 'indonesia');
        if ($criteria['lokasi_tipe'] === 'dalam' && !$isActuallyDalam) {
            $reasons[] = "lokasi_tipe is 'dalam' but country is '$negaraLower'";
        }
        if ($criteria['lokasi_tipe'] === 'luar' && $isActuallyDalam) {
            $reasons[] = "lokasi_tipe is 'luar' but country is '$negaraLower'";
        }
    }
    
    // Deadline / Still Open filter
    if (!empty($criteria['sort_deadline']) || !empty($criteria['still_open'])) {
        $parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
        $parseDeadlineDate->setAccessible(true);
        $deadlineTime = $parseDeadlineDate->invoke($controller, $r->deadline ?? '');
        if ($deadlineTime !== null && $deadlineTime < time()) {
            $reasons[] = "expired (deadline: {$r->deadline}, parsed: " . date('Y-m-d H:i:s', $deadlineTime) . ", now: " . date('Y-m-d H:i:s') . ")";
        }
    }
    
    // Let's check if there are other criteria active:
    // Bulan filter
    if (!empty($criteria['bulan'])) {
        $reasons[] = "bulan is set: " . json_encode($criteria['bulan']);
    }

    if (empty($reasons)) {
        echo "ID {$r->id} ({$r->nama_beasiswa}): PASSED\n";
    } else {
        echo "ID {$r->id} ({$r->nama_beasiswa}): FAILED due to " . implode(', ', $reasons) . "\n";
    }
}

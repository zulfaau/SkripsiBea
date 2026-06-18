<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "kalau beasiswa yg masi buka di dn ada apa aja";

// Normalize
$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

echo "Normalized: $normalizedText\n";

// Criteria
$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

echo "Criteria: " . json_encode($criteria, JSON_PRETTY_PRINT) . "\n";

// StopWords and cleanQuery
$stopWords = [
    'deadline', 'deadlinenya', 'dl', 'dlnya', 'dl nya', 'terdekat', 'terjauh', 'dekat', 'jauh', 'mepet', 'tercepat', 'terlama', 'paling',
    'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke', 'gimana', 'cara', 'daftar',
    'gratis', 'full', 'fully', 'funded', 'sebagian', 'parsial', 'partial', 'biaya', 'pendanaan',
    's1', 's2', 's3', 'd3', 'd4', 'sarjana', 'magister', 'doktor', 'diploma',
    'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan',
    'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'bagi', 'minta', 'info', 'data', 'list', 'kumpulan', 'tampilkan', 'carikan', 'nyari',
    'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'masih', 'buka', 'tutup', 'aktif', 'terbuka', 'sekarang', 'saat'
];

$searchQueryClean = $normalizedText;
foreach ($stopWords as $sw) {
    $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
}
$searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));

if (strlen($searchQueryClean) > 3) {
    $searchQuery = $searchQueryClean;
} else {
    $searchQuery = $normalizedText;
}

if (strlen($searchQuery) < 15 && !empty($criteria['negara'])) {
    $searchQuery = "beasiswa " . implode(' ', $criteria['negara']);
}

echo "SearchQuery sent to DB: '$searchQuery'\n";

$generateEmbedding = $reflector->getMethod('generateEmbedding');
$generateEmbedding->setAccessible(true);
$embedding = $generateEmbedding->invoke($controller, $searchQuery);

$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $searchQuery, '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

echo "Raw results count: " . count($rawResults) . "\n";

$applyStrictFilters = $reflector->getMethod('applyStrictFilters');
$applyStrictFilters->setAccessible(true);
$filtered = $applyStrictFilters->invoke($controller, $rawResults, $criteria);

echo "Filtered count: " . count($filtered) . "\n";

foreach ($rawResults as $r) {
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
    
    // Negara filter
    if (!empty($criteria['negara'])) {
        $m = false;
        $rowNegara = strtolower($r->negara ?? '');
        foreach ($criteria['negara'] as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/i', $rowNegara)) {
                $m = true;
                break;
            }
        }
        if (!$m) $reasons[] = "country '$rowNegara' does not match requested: " . json_encode($criteria['negara']);
    }
    
    // Deadline / Still Open filter
    if (!empty($criteria['sort_deadline']) || !empty($criteria['still_open'])) {
        $parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
        $parseDeadlineDate->setAccessible(true);
        $deadlineTime = $parseDeadlineDate->invoke($controller, $r->deadline ?? '');
        if ($deadlineTime !== null && $deadlineTime < time()) {
            $reasons[] = "expired (deadline: {$r->deadline}, parsed: " . date('Y-m-d H:i:s', $deadlineTime) . ")";
        }
    }

    if (!empty($reasons)) {
        echo "ID {$r->id} ({$r->nama_beasiswa}): FAILED due to " . implode(', ', $reasons) . "\n";
    } else {
        echo "ID {$r->id} ({$r->nama_beasiswa}): PASSED\n";
    }
}


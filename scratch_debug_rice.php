<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa masak nasi goreng";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

// Clean subject words manually as done in handleSearch
$stopWords = [
    'deadline', 'deadlinenya', 'dl', 'dlnya', 'dl nya', 'terdekat', 'terjauh', 'dekat', 'jauh', 'mepet', 'tercepat', 'terlama', 'paling',
    'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke', 'gimana', 'cara', 'daftar',
    'gratis', 'full', 'fully', 'funded', 'sebagian', 'parsial', 'partial', 'biaya', 'pendanaan',
    's1', 's2', 's3', 'd3', 'd4', 'sarjana', 'magister', 'doktor', 'diploma'
];
$searchQueryClean = $normalizedText;
foreach ($stopWords as $sw) {
    $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
}
$searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
$cleanWords = explode(' ', $searchQueryClean);
$cleanWords = array_filter($cleanWords, fn($w) => strlen($w) >= 3 && !in_array($w, ['beasiswa', 'scholarship', 'kuliah', 'studi', 'luar', 'dalam', 'negeri', 'indonesia']));
$criteria['clean_subject_words'] = array_values($cleanWords);

echo "clean_subject_words: " . json_encode($criteria['clean_subject_words']) . "\n";

// Get raw results
$generateEmbedding = $reflector->getMethod('generateEmbedding');
$generateEmbedding->setAccessible(true);
$embedding = $generateEmbedding->invoke($controller, $normalizedText);

$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $normalizedText, '[' . implode(',', $embedding) . ']', 5
]);
$ids = array_map(fn($r) => $r->id, $searchIds);

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

foreach ($rawResults as $r) {
    echo "\nID: {$r->id} - {$r->nama_beasiswa}\n";
    $searchArea = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->negara ?? '') . ' ' . ($r->benua ?? '') . ' ' . ($r->bidang ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? ''));
    
    foreach ($criteria['clean_subject_words'] as $word) {
        $pos = strpos($searchArea, $word);
        if ($pos !== false) {
            echo "  - MATCHED WORD: '$word' at position $pos! Snippet: " . substr($searchArea, max(0, $pos - 10), 30) . "\n";
        } else {
            echo "  - NO MATCH for '$word'\n";
        }
    }
}

<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa luar negeri deadline terdekat ada apa";

// Clean the query:
$stopWords = [
    'deadline', 'deadlinenya', 'dl', 'dlnya', 'terdekat', 'terjauh', 'dekat', 'jauh', 'mepet', 'tercepat', 'terlama', 'paling',
    'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke', 'gimana', 'cara', 'daftar',
    'gratis', 'full', 'fully', 'funded', 'sebagian', 'parsial', 'partial', 'biaya', 'pendanaan',
    's1', 's2', 's3', 'd3', 'd4', 'sarjana', 'magister', 'doktor', 'diploma'
];

$words = explode(' ', $message);
$cleanedWords = array_filter($words, fn($w) => !in_array($w, $stopWords));
$cleanedQuery = implode(' ', $cleanedWords);

echo "Original: $message\n";
echo "Cleaned: $cleanedQuery\n";

$generateEmbedding = $reflector->getMethod('generateEmbedding');
$generateEmbedding->setAccessible(true);
$embedding = $generateEmbedding->invoke($controller, $cleanedQuery);

$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $cleanedQuery, '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

// Urutkan
$idMap = array_flip($ids);
$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $message);
$criteria['sort_deadline'] = true;
$criteria['sort_deadline_dir'] = 'asc';

$applyStrictFilters = $reflector->getMethod('applyStrictFilters');
$applyStrictFilters->setAccessible(true);
$filtered = $applyStrictFilters->invoke($controller, $rawResults, $criteria);

echo "Filtered count: " . count($filtered) . "\n";
foreach (array_slice($filtered, 0, 5) as $f) {
    echo "ID: {$f->id} - {$f->nama_beasiswa} - Negara: {$f->negara} - Deadline: {$f->deadline}\n";
}

<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$message = 'ada beasiswa s3 tahun 2026?';

$request = Request::create('/chatbot/ask', 'POST', [
    'message' => $message,
    'rag_enabled' => true
]);

// Set empty session store on the request so session helper works
$sessionStore = $app->make('session')->driver('array');
$request->setLaravelSession($sessionStore);

$controller = new \App\Http\Controllers\ChatbotController();
$reflector = new \ReflectionClass($controller);

// Let's run ask() and capture intermediate states by calling methods directly
$normalizeTextMethod = $reflector->getMethod('normalizeText');
$normalizeTextMethod->setAccessible(true);
$normalizedData = $normalizeTextMethod->invoke($controller, $message);
$normalizedText = $normalizedData['text'];

echo "1. Normalized Text: " . $normalizedText . "\n";

$isSearchQueryMethod = $reflector->getMethod('isSearchQuery');
$isSearchQueryMethod->setAccessible(true);
$isSearch = $isSearchQueryMethod->invoke($controller, $normalizedText);
echo "2. isSearchQuery: " . ($isSearch ? "YES" : "NO") . "\n";

// Let's run handleSearch
$handleSearchMethod = $reflector->getMethod('handleSearch');
$handleSearchMethod->setAccessible(true);

$response = $handleSearchMethod->invoke($controller, $message, $normalizedText, $normalizedData);

echo "3. handleSearch Response Status Code: " . $response->getStatusCode() . "\n";
echo "4. handleSearch Content: " . $response->getContent() . "\n";

// Let's inspect variables by replicating handleSearch logic
$extractCriteriaMethod = $reflector->getMethod('extractCriteria');
$extractCriteriaMethod->setAccessible(true);
$criteria = $extractCriteriaMethod->invoke($controller, $normalizedText);
echo "5. Extracted Criteria:\n";
print_r($criteria);

$stopWords = [
    'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke',
    'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong',
    'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'siapa', 'bagaimana', 'gimana'
];

$searchQueryClean = $normalizedText;
foreach ($stopWords as $sw) {
    $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
}
$searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
echo "6. searchQueryClean: " . $searchQueryClean . "\n";

$generateEmbeddingMethod = $reflector->getMethod('generateEmbedding');
$generateEmbeddingMethod->setAccessible(true);
$embedding = $generateEmbeddingMethod->invoke($controller, $searchQueryClean);

$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $searchQueryClean, '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);
echo "7. hybrid_search count for clean query: " . count($ids) . "\n";

if (!empty($ids)) {
    $rawResults = DB::table('scholarships')->whereIn('id', $ids)->get()->all();
    $applyStrictFiltersMethod = $reflector->getMethod('applyStrictFilters');
    $applyStrictFiltersMethod->setAccessible(true);
    $filtered = $applyStrictFiltersMethod->invoke($controller, $rawResults, $criteria);
    echo "8. Filtered count for clean query: " . count($filtered) . "\n";
}

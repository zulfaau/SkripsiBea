<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$message = "ada beasiswa s2 tahun 2026?";

$controller = new \App\Http\Controllers\ChatbotController();
$reflector = new \ReflectionClass($controller);

$normalizeTextMethod = $reflector->getMethod('normalizeText');
$normalizeTextMethod->setAccessible(true);
$normalizedData = $normalizeTextMethod->invoke($controller, $message);
$normalizedText = $normalizedData['text'];

$extractCriteriaMethod = $reflector->getMethod('extractCriteria');
$extractCriteriaMethod->setAccessible(true);
$criteria = $extractCriteriaMethod->invoke($controller, $normalizedText);

echo "Normalized Text: " . $normalizedText . "\n";
echo "Extracted Criteria:\n";
print_r($criteria);

// Generate Embedding
$generateEmbeddingMethod = $reflector->getMethod('generateEmbedding');
$generateEmbeddingMethod->setAccessible(true);
$embedding = $generateEmbeddingMethod->invoke($controller, $normalizedText);

// Get raw results from hybrid_search
$searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $normalizedText, '[' . implode(',', $embedding) . ']', 100
]);
$ids = array_map(fn($r) => $r->id, $searchIds);
echo "Hybrid Search IDs returned: " . count($ids) . "\n";

if (!empty($ids)) {
    $rawResults = DB::table('scholarships')
        ->whereIn('id', $ids)
        ->get()
        ->all();
    
    echo "Raw Results Count: " . count($rawResults) . "\n";
    
    $applyStrictFiltersMethod = $reflector->getMethod('applyStrictFilters');
    $applyStrictFiltersMethod->setAccessible(true);
    $filtered = $applyStrictFiltersMethod->invoke($controller, $rawResults, $criteria);
    
    echo "Filtered Results Count: " . count($filtered) . "\n";
}

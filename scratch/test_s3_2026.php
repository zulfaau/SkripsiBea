<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$message = "beasiswa s1 yang tutup bulan september";

// Call extractCriteria to see what filters are extracted
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
    
    // Apply filters step-by-step
    $applyStrictFiltersMethod = $reflector->getMethod('applyStrictFilters');
    $applyStrictFiltersMethod->setAccessible(true);
    $filtered = $applyStrictFiltersMethod->invoke($controller, $rawResults, $criteria);
    
    echo "Filtered Results Count: " . count($filtered) . "\n";
    if (empty($filtered)) {
        echo "Let's inspect why raw results were filtered out:\n";
        $i = 0;
        foreach ($rawResults as $r) {
            $i++;
            if ($i > 5) break;
            echo "--- Item {$i}: {$r->nama_beasiswa} ---\n";
            echo "Jenjang: {$r->jenjang} | Deadline: {$r->deadline}\n";
            
            // Check jenjang match
            $jenjangMatched = false;
            foreach ($criteria['jenjang'] as $l) {
                if (str_contains(strtoupper($r->jenjang ?? ''), $l)) {
                    $jenjangMatched = true;
                }
            }
            echo "Jenjang Matched: " . ($jenjangMatched ? "YES" : "NO") . "\n";
            
            // Check year match
            $yearMatched = false;
            foreach ($criteria['tahun'] as $y) {
                if (str_contains($r->deadline ?? '', $y) || str_contains($r->nama_beasiswa ?? '', $y)) {
                    $yearMatched = true;
                }
            }
            echo "Year Matched: " . ($yearMatched ? "YES" : "NO") . "\n";
            
            // Check clean subject words match
            if (!empty($criteria['clean_subject_words'])) {
                $hasWordMatch = false;
                $searchArea = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->negara ?? '') . ' ' . ($r->benua ?? '') . ' ' . ($r->bidang ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? ''));
                foreach ($criteria['clean_subject_words'] as $word) {
                    if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $searchArea)) {
                        $hasWordMatch = true;
                    }
                }
                echo "Clean Words Matched: " . ($hasWordMatch ? "YES" : "NO") . " (Words: " . implode(', ', $criteria['clean_subject_words']) . ")\n";
            }
        }
    }
}

<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\ChatbotController::class);
$reflector = new ReflectionClass($controller);

$methodEmbed = $reflector->getMethod('generateEmbedding');
$methodEmbed->setAccessible(true);

$requestText = "beasiswa s1 luar negeri yang tutup bulan oktober";
$stopWords = ['ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke', 'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'siapa', 'bagaimana', 'gimana'];
$searchQueryClean = $requestText;
foreach ($stopWords as $sw) {
    $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
}
$searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
$searchQuery = strlen($searchQueryClean) > 3 ? $searchQueryClean : $requestText;

$embedding = $methodEmbed->invoke($controller, $searchQuery);
echo "Embedding generated: " . count($embedding) . " dimensions.\n";

$searchIds = \Illuminate\Support\Facades\DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
    $searchQuery, '[' . implode(',', $embedding) . ']', 100
]);

echo "Hybrid search found " . count($searchIds) . " ids.\n";

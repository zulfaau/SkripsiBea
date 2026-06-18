<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Http\Request;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$queries = [
    "ada beasiswa s3 tahun 2026?",
    "beasiswa s1 yang tutup bulan september",
    "ada beasiswa s2 tahun 2026?",
    "beasiswa s1 luar negeri yang tutup bulan mei"
];

echo "==================================================\n";
echo "       TESTING USER QUERY VARIATIONS              \n";
echo "==================================================\n";

foreach ($queries as $q) {
    session()->flush();
    $request = Request::create('/api/chatbot', 'POST', [
        'message' => $q,
        'rag_enabled' => true
    ]);
    
    // Call ask
    $response = $controller->ask($request);
    
    // Read internal variables via Reflection
    $intentProp = $reflector->getProperty('currentIntent');
    $intentProp->setAccessible(true);
    $actualIntent = $intentProp->getValue($controller);
    
    $normalizeText = $reflector->getMethod('normalizeText');
    $normalizeText->setAccessible(true);
    $norm = $normalizeText->invoke($controller, $q);
    $normalizedText = $norm['text'];
    
    $extractCriteria = $reflector->getMethod('extractCriteria');
    $extractCriteria->setAccessible(true);
    $criteria = $extractCriteria->invoke($controller, $normalizedText);
    
    echo "Query      : '{$q}'\n";
    echo "Normalized : '{$normalizedText}'\n";
    echo "Intent     : '{$actualIntent}'\n";
    echo "Criteria   : " . json_encode([
        'jenjang' => $criteria['jenjang'],
        'lokasi_tipe' => $criteria['lokasi_tipe'],
        'negara' => $criteria['negara']
    ]) . "\n";
    echo "Response   : " . substr(strip_tags($response->getData()->answer), 0, 150) . "...\n";
    echo "--------------------------------------------------\n";
}

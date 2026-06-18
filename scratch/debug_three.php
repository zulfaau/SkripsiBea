<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Http\Request;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);
$intentProp = $reflector->getProperty('currentIntent');
$intentProp->setAccessible(true);

$queries = [
    'selamat siang',
    'mantap',
    'paham kak'
];

foreach ($queries as $q) {
    session()->flush();
    $request = Request::create('/api/chatbot', 'POST', [
        'message' => $q,
        'rag_enabled' => true
    ]);
    
    // We will call ask and output the debug details
    $response = $controller->ask($request);
    $actualIntent = $intentProp->getValue($controller);
    
    // Also let's run normalizeText to see what it became
    $normalizeText = $reflector->getMethod('normalizeText');
    $normalizeText->setAccessible(true);
    $norm = $normalizeText->invoke($controller, $q);
    
    echo "Query: '{$q}'\n";
    echo "Normalized: '{$norm['text']}' (hasTypo: " . ($norm['hasTypo'] ? 'yes' : 'no') . ", typo: '{$norm['typoWord']}' -> '{$norm['correctedWord']}')\n";
    echo "Actual Intent: '{$actualIntent}'\n";
    echo "Response: " . json_encode($response->getData()) . "\n";
    echo "--------------------------------------------------\n";
}

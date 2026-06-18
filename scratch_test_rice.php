<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Session;

Session::setDefaultDriver('array');
$session = Session::driver('array');
$app['request']->setLaravelSession($session);

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa masak nasi goreng";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

echo "Normalized: $normalizedText\n";

$handleSearch = $reflector->getMethod('handleSearch');
$handleSearch->setAccessible(true);

try {
    $response = $handleSearch->invoke($controller, $message, $normalizedText, $norm, null);
    $data = json_decode($response->getContent(), true);
    echo "Success: " . ($data['success'] ? 'YES' : 'NO') . "\n";
    echo "Answer:\n" . ($data['answer'] ?? 'NO ANSWER') . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

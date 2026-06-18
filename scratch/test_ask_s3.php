<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$request = Request::create('/chatbot/ask', 'POST', [
    'message' => 'ada beasiswa s3 tahun 2026?',
    'rag_enabled' => true
]);

$controller = new \App\Http\Controllers\ChatbotController();
$response = $controller->ask($request);

echo "Status Code: " . $response->getStatusCode() . "\n";
echo "Content: \n" . $response->getContent() . "\n";

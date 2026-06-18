<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Http\Request;

$message = "beasiswa s2 fully funded ln";
echo "Testing live chatbot query: \"{$message}\"\n";

try {
    $controller = new ChatbotController();
    $request = Request::create('/api/chatbot', 'POST', [
        'message' => $message,
        'rag_enabled' => true
    ]);
    
    $response = $controller->ask($request);
    echo "Response Content: " . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "❌ Error caught: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Http\Request;

$controller = new ChatbotController();
$request = Request::create('/api/ask', 'POST', [
    'message' => 'kalau beasiswa yg masi buka di dn ada apa aja',
    'rag_enabled' => true
]);

$response = $controller->ask($request);
$data = json_decode($response->getContent(), true);

echo "Success: " . ($data['success'] ? 'YES' : 'NO') . "\n";
echo "Answer:\n" . ($data['answer'] ?? 'NO ANSWER') . "\n";

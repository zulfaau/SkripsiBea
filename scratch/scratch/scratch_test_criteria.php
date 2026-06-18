<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);
$extract = $reflector->getMethod('extractCriteria');
$extract->setAccessible(true);
$criteria = $extract->invoke($controller, 'kalau beasiswa yang masih buka di dalam negeri ada apa');
print_r($criteria);

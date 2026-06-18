<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\ChatbotController::class);
$reflector = new ReflectionClass($controller);

// test extractCriteria
$method = $reflector->getMethod('extractCriteria');
$method->setAccessible(true);
$requestText = "beasiswa s1 luar negeri yang tutup bulan oktober";
$criteria = $method->invoke($controller, $requestText);
print_r($criteria);

// Test hybrid_search and filtering
$methodSearch = $reflector->getMethod('handleSearch');
$methodSearch->setAccessible(true);
$res = $methodSearch->invoke($controller, $requestText, $requestText, [], null);

// If I want to trace the filters, let me just see the criteria first.

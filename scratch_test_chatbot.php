<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\ChatbotController::class);
$reflector = new ReflectionClass($controller);
$method = $reflector->getMethod('handleSearch');
$method->setAccessible(true);

$requestText = "beasiswa s1 luar negeri yang tutup bulan oktober";
$normalizedData = [];
$res = $method->invoke($controller, $requestText, $requestText, $normalizedData, null);

print_r($res);

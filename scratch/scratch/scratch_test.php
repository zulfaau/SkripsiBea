<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app()->make(\App\Http\Controllers\ChatbotController::class);
$reflector = new ReflectionClass($controller);
$methodSearch = $reflector->getMethod('handleSearch');
$methodSearch->setAccessible(true);

// We will test by temporarily redefining the DB query in ChatbotController or just running the logic here
// Wait, we can't easily mock DB::select. Let's just modify the controller and run the test.

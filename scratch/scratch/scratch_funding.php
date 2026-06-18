<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$scholarships = DB::table('scholarships')->get();
$now = time();
$futureCount = 0;
$pastCount = 0;

$reflector = new ReflectionClass(App\Http\Controllers\ChatbotController::class);
$parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
$parseDeadlineDate->setAccessible(true);
$controller = new App\Http\Controllers\ChatbotController();

foreach ($scholarships as $s) {
    $parsed = $parseDeadlineDate->invoke($controller, $s->deadline);
    $status = 'Not Parsable / Null';
    if ($parsed !== null) {
        if ($parsed >= $now) {
            $status = 'FUTURE (' . date('Y-m-d', $parsed) . ')';
            $futureCount++;
        } else {
            $status = 'PAST (' . date('Y-m-d', $parsed) . ')';
            $pastCount++;
        }
    }
    echo "ID: {$s->id} - {$s->nama_beasiswa} - Deadline: {$s->deadline} - Status: {$status}\n";
}

echo "\nSummary: Future/Open: $futureCount, Past/Closed: $pastCount, Total: " . count($scholarships) . "\n";

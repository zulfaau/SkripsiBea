<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa yg dl nya belum lewat apa aja?";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

echo "Normalized Text: $normalizedText\n";
echo "Criteria extracted: " . json_encode($criteria, JSON_PRETTY_PRINT) . "\n";

// Let's test parseDeadlineDate for the specific deadlines:
$deadlines = [
    '2026-04-12 00:00:00',
    '06 Jun 2026',
    '28 Mei 2026',
    '2026-04-06 00:00:00',
    '2026-04-30 00:00:00'
];

$parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
$parseDeadlineDate->setAccessible(true);

echo "\n--- parseDeadlineDate tests (System Time: " . time() . " / " . date('Y-m-d H:i:s') . ") ---\n";
foreach ($deadlines as $d) {
    $parsed = $parseDeadlineDate->invoke($controller, $d);
    $parsedStr = $parsed ? date('Y-m-d H:i:s', $parsed) : 'null';
    $isLessThanNow = ($parsed !== null && $parsed < time());
    echo "Deadline string: '$d' -> Parsed: $parsedStr (less than now: " . ($isLessThanNow ? 'YES' : 'NO') . ")\n";
}

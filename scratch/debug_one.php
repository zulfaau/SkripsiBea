<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;
use Illuminate\Http\Request;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$q = "apa arti beasiswa penuh?";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $q);
$normalizedText = $norm['text'];

$handleFAQ = $reflector->getMethod('handleFAQ');
$handleFAQ->setAccessible(true);
$faqAns = $handleFAQ->invoke($controller, $normalizedText);

echo "Query: '{$q}'\n";
echo "Normalized: '{$normalizedText}' (hasTypo: " . ($norm['hasTypo'] ? 'yes' : 'no') . ")\n";
echo "handleFAQ returned: " . ($faqAns === null ? 'NULL' : "'$faqAns'") . "\n";

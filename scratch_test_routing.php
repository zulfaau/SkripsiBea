<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "kalau beasiswa yg masi buka di dn ada apa aja";
$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

echo "1. Original: '$message'\n";
echo "2. Normalized: '$normalizedText'\n";
echo "   hasTypo: " . ($norm['hasTypo'] ? 'YES' : 'NO') . "\n";
echo "   typoWord: " . $norm['typoWord'] . "\n";
echo "   correctedWord: " . $norm['correctedWord'] . "\n";

$isGreetingMethod = $reflector->getMethod('isGreeting');
$isGreetingMethod->setAccessible(true);
$isGreetingVal = $isGreetingMethod->invoke($controller, $normalizedText);
echo "3. isGreeting(): " . ($isGreetingVal ? 'TRUE' : 'FALSE') . "\n";

$isSearchQueryMethod = $reflector->getMethod('isSearchQuery');
$isSearchQueryMethod->setAccessible(true);
$isSearchQueryVal = $isSearchQueryMethod->invoke($controller, $normalizedText);
echo "4. isSearchQuery(): " . ($isSearchQueryVal ? 'TRUE' : 'FALSE') . "\n";

$isOutOfTopicMethod = $reflector->getMethod('isOutOfTopic');
$isOutOfTopicMethod->setAccessible(true);
$isOutOfTopicVal = $isOutOfTopicMethod->invoke($controller, $normalizedText);
echo "5. isOutOfTopic(): " . ($isOutOfTopicVal ? 'TRUE' : 'FALSE') . "\n";

// Line 355 regex check
$isGreetingRegexVal = preg_match('/\b(ha+i+|hi+|ha+lo+|ha+llo+|he+lo+|he+llo+|pagi+|siang+|sore+|malam+|tanya+|nanya+|makasih+|thanks+|thank you|mks+|pilih|nomor|no|nmr|#|yang lain|selanjutnya|berikutnya)\b/i', $normalizedText);
echo "6. Line 355 regex isGreeting: " . ($isGreetingRegexVal ? 'TRUE' : 'FALSE') . "\n";

// Logika Utama check
$isSearch = $isSearchQueryVal;
$detailIntentMethod = $reflector->getMethod('getDetailIntent');
$detailIntentMethod->setAccessible(true);
$detailIntentVal = $detailIntentMethod->invoke($controller, $normalizedText);
$isDetail = ($detailIntentVal !== null);

echo "7. isSearch: " . ($isSearch ? 'TRUE' : 'FALSE') . "\n";
echo "8. isDetail: " . ($isDetail ? 'TRUE' : 'FALSE') . "\n";

$ragEnabled = true;
$willBlock = ($ragEnabled && !$isGreetingRegexVal && !$isSearch && !$isDetail);
echo "9. Will it block as Out of Topic at line 361? " . ($willBlock ? 'YES' : 'NO') . "\n";

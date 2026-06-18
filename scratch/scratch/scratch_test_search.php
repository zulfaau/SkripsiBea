<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$message = "beasiswa ln dl terdekat ada apa saja";

$reflector = new ReflectionClass(ChatbotController::class);

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

$criteria['sort_deadline'] = true;
$criteria['sort_deadline_dir'] = 'asc';

$ids = [550, 564, 639, 554, 619, 562, 643, 560, 657, 648, 636, 551, 557, 590, 637, 583, 601, 602, 567, 644, 258, 635, 542, 650, 600, 541, 552, 649, 565, 603, 634, 232, 645, 651, 436, 559, 147, 632, 274, 629, 586, 106, 591, 624, 546, 606, 612, 535, 558, 621, 588, 536, 622, 653, 543, 599, 270, 544, 609, 608, 179, 255, 555, 617, 623, 605, 584, 630, 549, 628, 607, 353, 336, 44, 610, 571, 589, 574, 248, 523, 615, 90, 245, 246, 539, 611, 647, 613, 576, 631, 429, 300, 641, 627, 540, 414, 247, 275, 263, 361];

$rawResults = DB::table('scholarships')
    ->whereIn('id', $ids)
    ->get()
    ->all();

$applyStrictFilters = $reflector->getMethod('applyStrictFilters');
$applyStrictFilters->setAccessible(true);
$filtered = $applyStrictFilters->invoke($controller, $rawResults, $criteria);

echo "After applyStrictFilters, " . count($filtered) . " results left.\n";
foreach ($rawResults as $r) {
    $rowNegara = strtolower($r->negara ?? '');
    $isIndo = str_contains($rowNegara, 'indonesia');
    $parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
    $parseDeadlineDate->setAccessible(true);
    $deadlineTime = $parseDeadlineDate->invoke($controller, $r->deadline ?? '');
    $timeFormatted = $deadlineTime ? date('Y-m-d', $deadlineTime) : 'null';
    $isExpired = ($deadlineTime !== null && $deadlineTime < time());
    
    // Check if it passes strict filter
    $passedFilter = false;
    foreach ($filtered as $f) {
        if ($f->id == $r->id) $passedFilter = true;
    }
    
    if ($passedFilter || $r->id == 263) {
        echo "ID: {$r->id} - {$r->nama_beasiswa} - Country: {$r->negara} - Deadline: {$r->deadline} (Parsed: {$timeFormatted}) - Expired: " . ($isExpired ? 'YES' : 'NO') . " - Passed: " . ($passedFilter ? 'YES' : 'NO') . "\n";
    }
}

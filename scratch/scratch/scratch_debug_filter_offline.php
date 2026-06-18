<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa ln dl terdekat ada apa saja";

$normalizeText = $reflector->getMethod('normalizeText');
$normalizeText->setAccessible(true);
$norm = $normalizeText->invoke($controller, $message);
$normalizedText = $norm['text'];

$extractCriteria = $reflector->getMethod('extractCriteria');
$extractCriteria->setAccessible(true);
$criteria = $extractCriteria->invoke($controller, $normalizedText);

if (preg_match('/\b(paling dekat|deadline dekat|mepet|terdekat|tercepat)\b/i', $normalizedText)) {
    $criteria['sort_deadline'] = true;
    $criteria['sort_deadline_dir'] = 'asc';
}

echo "Normalized Message: $normalizedText\n";
echo "Criteria: " . json_encode($criteria, JSON_PRETTY_PRINT) . "\n";

// Get all scholarships directly from database
$rawResults = DB::table('scholarships')->get()->all();
echo "Total scholarships in DB: " . count($rawResults) . "\n";

$applyStrictFilters = $reflector->getMethod('applyStrictFilters');
$applyStrictFilters->setAccessible(true);
$filtered = $applyStrictFilters->invoke($controller, $rawResults, $criteria);
echo "Filtered count: " . count($filtered) . "\n";

// Print the top 5 passed results
$i = 0;
foreach ($filtered as $f) {
    echo "PASSED ID: {$f->id} - {$f->nama_beasiswa} - Negara: {$f->negara} - Deadline: {$f->deadline}\n";
    $i++;
    if ($i >= 5) break;
}

// Print status of specific scholarship (e.g. ID 263)
foreach ($rawResults as $r) {
    if ($r->id == 263) {
        $negaraLower = strtolower($r->negara ?? '');
        $isActuallyDalam = str_contains($negaraLower, 'indonesia');
        $parseDeadlineDate = $reflector->getMethod('parseDeadlineDate');
        $parseDeadlineDate->setAccessible(true);
        $deadlineTime = $parseDeadlineDate->invoke($controller, $r->deadline ?? '');
        $isExpired = ($deadlineTime !== null && $deadlineTime < time());
        
        echo "\n--- Trace ID 263 ---\n";
        echo "Nama: {$r->nama_beasiswa}\n";
        echo "Negara: {$r->negara} (lokasi_tipe matching luar: " . ($isActuallyDalam ? 'NO' : 'YES') . ")\n";
        echo "Deadline: {$r->deadline} (Parsed: " . ($deadlineTime ? date('Y-m-d H:i:s', $deadlineTime) : 'null') . ", expired: " . ($isExpired ? 'YES' : 'NO') . ")\n";
        
        // check passed
        $passed = false;
        foreach ($filtered as $f) {
            if ($f->id == 263) $passed = true;
        }
        echo "Passed: " . ($passed ? 'YES' : 'NO') . "\n";
    }
}

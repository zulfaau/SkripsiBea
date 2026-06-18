<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$generateEmbedding = $reflector->getMethod('generateEmbedding');
$generateEmbedding->setAccessible(true);

$queries = [
    "beasiswa ln dl terdekat ada apa saja",
    "beasiswa luar negeri deadline terdekat ada apa saja",
    "beasiswa luar negeri",
];

foreach ($queries as $q) {
    echo "\n=== QUERY: $q ===\n";
    try {
        $embedding = $generateEmbedding->invoke($controller, $q);
        $searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
            $q, '[' . implode(',', $embedding) . ']', 100
        ]);
        $ids = array_map(fn($r) => $r->id, $searchIds);
        echo "Returned " . count($ids) . " IDs. First 10 IDs: " . implode(', ', array_slice($ids, 0, 10)) . "\n";
        
        // Let's see if ID 263 (Beasiswa Universitas Islam Madinah, which has deadline 01 Des 2026, Luar Negeri) is in the results:
        if (in_array(263, $ids)) {
            echo "SUCCESS: ID 263 IS in the results!\n";
        } else {
            echo "FAILED: ID 263 is NOT in the results!\n";
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

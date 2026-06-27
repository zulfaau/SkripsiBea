<?php
// Script untuk mengetes Response Time (Waktu Respon) Chatbot Scholarbot
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$controller = $app->make(App\Http\Controllers\ChatbotController::class);
$store = $app['session']->driver();
$store->start();

// Sampel pertanyaan yang merepresentasikan berbagai skenario:
// Greeting, FAQ, Pencarian Sederhana, dan Pencarian Kompleks.
$queries = [
    'Halo',                                      // Greeting (Murni AI/Cepat)
    'Apa itu beasiswa partially funded?',        // FAQ (Cepat)
    'Beasiswa S1 di Jepang',                     // Search simple (Membutuhkan RAG)
    'Beasiswa S2 di Australia',                  // Search simple
    'Beasiswa fully funded tanpa TOEFL',         // Search dengan filter khusus
    'Beasiswa untuk mahasiswa kurang mampu',     // Search dengan filter khusus
    'Beasiswa S2 di benua Eropa untuk jurusan kedokteran yang fully funded', // Search kompleks (Banyak Kombinasi)
    'Beasiswa deadline terdekat luar negeri yang tutup bulan ini',           // Search kompleks (Waktu)
];

function askOnce($app, $controller, $store, $q) {
    // Reset session agar tiap pertanyaan berjalan independen (tanpa context riwayat chat)
    $store->flush(); 
    $request = Request::create('/chatbot/ask', 'POST', ['message' => $q, 'rag_enabled' => true]);
    $request->setLaravelSession($store);
    $app->instance('request', $request);
    try {
        $resp = $controller->ask($request);
        $data = json_decode($resp->getContent(), true);
        if (isset($data['answer'])) return ['ok' => true, 'ans' => $data['answer']];
        return ['ok' => false, 'ans' => ($data['message'] ?? $resp->getContent())];
    } catch (\Throwable $e) {
        return ['ok' => false, 'ans' => '[ERROR] ' . $e->getMessage()];
    }
}

echo "=========================================================\n";
echo "PENGUJIAN RESPONSE TIME (LATENCY) SCHOLARBOT\n";
echo "=========================================================\n\n";

$times = [];
$totalTime = 0;

foreach ($queries as $i => $q) {
    $no = $i + 1;
    echo "[$no] Q: $q\n";
    
    // Mulai menghitung waktu
    $start = microtime(true);
    
    // Tembak request ke chatbot
    $res = askOnce($app, $controller, $store, $q);
    
    // Berhenti menghitung waktu
    $end = microtime(true);
    
    $elapsed = round($end - $start, 2);
    $times[] = $elapsed;
    $totalTime += $elapsed;
    
    $status = $res['ok'] ? "OK" : "ERROR";
    
    // Ambil sepenggal jawaban agar tidak memenuhi layar saat diprint
    $snippet = mb_substr(str_replace("\n", " ", $res['ans']), 0, 70) . '...';
    
    echo "    Waktu : {$elapsed} detik | Status: $status\n";
    echo "    Ans   : $snippet\n";
    echo str_repeat("-", 57) . "\n";
}

$avg = round($totalTime / count($times), 2);
$min = min($times);
$max = max($times);

echo "\n=========================================================\n";
echo "RINGKASAN PERFORMA WAKTU\n";
echo "=========================================================\n";
echo "Total Pertanyaan : " . count($queries) . "\n";
echo "Rata-rata Waktu  : {$avg} detik\n";
echo "Waktu Tercepat   : {$min} detik\n";
echo "Waktu Terlama    : {$max} detik\n";
echo "=========================================================\n";

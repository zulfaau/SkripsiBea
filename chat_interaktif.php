<?php
// Script Interactive Chatbot CLI untuk merekam Waktu Respon ke dalam file CSV (Excel)
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$controller = $app->make(App\Http\Controllers\ChatbotController::class);
$store = $app['session']->driver();
$store->start();

// Setup File CSV
$csvFile = __DIR__ . '/log_response_time.csv';
$isNewFile = !file_exists($csvFile);

// Buka file mode 'a' (append) agar menambah di baris bawah tanpa menghapus data lama
$file = fopen($csvFile, 'a');

// Tulis header jika ini file baru
if ($isNewFile) {
    fputcsv($file, ['Waktu Akses', 'Pertanyaan', 'Response Time (Detik)', 'Status']);
}

function askChatbot($app, $controller, $store, $q) {
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
echo "💬 SCHOLARBOT INTERACTIVE CLI & LOGGER\n";
echo "=========================================================\n";
echo "Setiap pertanyaan yang Anda ketik akan otomatis direkam ke tabel:\n";
echo "📁 log_response_time.csv\n";
echo "Ketik 'exit' atau 'quit' untuk berhenti dan menutup program.\n";
echo "=========================================================\n\n";

while (true) {
    $input = readline("👤 Pertanyaan Anda : ");
    $input = trim($input);

    if (strtolower($input) === 'exit' || strtolower($input) === 'quit') {
        echo "\n👋 Menutup program. Seluruh riwayat tes sudah tersimpan di log_response_time.csv\n";
        break;
    }

    if (empty($input)) continue;

    echo "⏳ ScholarBot memproses jawaban...\n";
    $start = microtime(true);
    
    $res = askChatbot($app, $controller, $store, $input);
    
    $end = microtime(true);
    $elapsed = round($end - $start, 3);
    $status = $res['ok'] ? "OK" : "ERROR";

    // Tampilkan cuplikan jawaban ke terminal
    $snippet = mb_substr(str_replace("\n", " ", $res['ans']), 0, 100) . (strlen($res['ans']) > 100 ? '...' : '');
    echo "🤖 Jawaban Bot     : $snippet\n";
    echo "⏱️ Response Time   : $elapsed detik | Status: $status\n";
    echo str_repeat("-", 57) . "\n\n";

    // Eksekusi Simpan ke CSV
    $waktuAkses = date('Y-m-d H:i:s');
    fputcsv($file, [$waktuAkses, $input, $elapsed, $status]);
}

fclose($file);

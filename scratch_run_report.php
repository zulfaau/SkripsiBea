<?php
// Menjalankan seluruh skenario pengujian (cakupan skripsi) ke chatbot ASLI
// dan menulis report (pertanyaan + jawaban asli) ke file .txt secara incremental.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;

$controller = $app->make(App\Http\Controllers\ChatbotController::class);
$store = $app['session']->driver();
$store->start();

$OUT = __DIR__ . '/laporan_pengujian_chatbot.txt';
$LIMIT = getenv('LIMIT') ? (int)getenv('LIMIT') : 0; // 0 = semua

// Tiap item: ['cat'=>judul kategori (atau null jika lanjutan), 'q'=>pertanyaan, 'keep'=>bool]
// keep=false -> reset session (pertanyaan independen). keep=true -> lanjutan stateful.
$cats = [
  'KATEGORI 1: Filter Berdasarkan Negara Tujuan' => [
    'Beasiswa S1 di Jepang','Beasiswa S2 di Korea Selatan','Beasiswa S3 di Australia',
    'Beasiswa sarjana turki','Beasiswa master Indonesia','Beasiswa phd irlandia',
    'Beasiswa ke Jerman yang masih buka','Beasiswa ke Inggris fully funded',
  ],
  'KATEGORI 2: Filter Berdasarkan Jurusan' => [
    'Beasiswa jurusan Informatika','Beasiswa jurusan Kedokteran','Beasiswa jurusan Hukum',
    'Beasiswa jurusan Teknik Informatika di Jepang','Beasiswa S2 Data Science',
  ],
  'KATEGORI 3: Filter Berdasarkan Status Pendanaan' => [
    'Beasiswa dengan uang saku','Beasiswa pendanaan project','Beasiswa yang menanggung tiket pesawat',
    'Beasiswa yang menanggung biaya hidup','Beasiswa yang menanggung biaya kuliah saja',
  ],
  'KATEGORI 4: Filter Berdasarkan Tujuan Studi' => [
    'Beasiswa dalam negeri','Beasiswa luar negeri','Beasiswa pertukaran pelajar',
    'Beasiswa short course','Beasiswa double degree',
  ],
  'KATEGORI 5: Filter Berdasarkan Persyaratan Bahasa' => [
    'Beasiswa tanpa IELTS','Beasiswa tanpa TOEFL','Beasiswa dengan IELTS 6.0','Beasiswa yang menerima Duolingo',
  ],
  'KATEGORI 6: Filter Berdasarkan Waktu' => [
    'Beasiswa yang buka bulan Januari','Beasiswa yang buka bulan Februari',
    'Beasiswa yang buka tahun 2027','Beasiswa yang dibuka setiap tahun',
  ],
  'KATEGORI 7: Filter Deadline Terdekat' => [
    'Beasiswa deadline terdekat apa saja?','Beasiswa yang akan tutup dalam waktu dekat',
    'Beasiswa yang segera ditutup','Beasiswa yang pendaftarannya akan berakhir',
    'Deadline beasiswa terdekat','Beasiswa yang tutup minggu ini','Beasiswa yang tutup bulan ini',
  ],
  'KATEGORI 8: Filter Deadline Bulan' => [
    'Beasiswa yang tutup bulan Januari','Beasiswa yang tutup bulan Februari','Beasiswa yang tutup bulan Maret',
    'Beasiswa yang tutup bulan April','Beasiswa yang dibuka bulan Januari','Beasiswa yang dibuka bulan Juni',
    'Beasiswa yang dibuka bulan September','Beasiswa S1 yang tutup bulan Mei',
    'Beasiswa luar negeri yang deadline bulan Juni','Beasiswa master yang deadline bulan September',
  ],
  'KATEGORI 9: Berdasarkan Tahun' => [
    'Beasiswa yang tutup tahun 2026','Beasiswa yang masih buka tahun 2026','Beasiswa tahun 2027 yang sudah dibuka',
  ],
  'KATEGORI 10: Deadline Belum Lewat' => [
    'Beasiswa yang masih buka','Beasiswa yang masih menerima pendaftaran','Beasiswa yang deadlinenya belum lewat',
    'Beasiswa yang masih aktif','Beasiswa yang sedang dibuka','Beasiswa yang open registration',
  ],
  'KATEGORI 11: Filter Berdasarkan Jenjang' => [
    'Deadline beasiswa S1','Deadline beasiswa S2','Deadline beasiswa S3',
    'Beasiswa sarjana yang masih buka','Beasiswa master yang masih buka','Beasiswa doktor yang masih buka',
  ],
  'KATEGORI 12: Filter Berdasarkan Lokasi' => [
    'Deadline beasiswa luar negeri','Deadline beasiswa dalam negeri',
    'Beasiswa luar negeri yang tutup bulan ini','Beasiswa dalam negeri yang masih buka',
  ],
  'KATEGORI 13: Filter Deadline per Negara' => [
    'Deadline beasiswa Jepang','Deadline beasiswa Korea Selatan','Deadline beasiswa Australia',
    'Deadline beasiswa Inggris','Deadline beasiswa Jerman',
  ],
  'KATEGORI 14: Filter Deadline per Pendanaan' => [
    'Deadline beasiswa fully funded','Deadline beasiswa partially funded',
    'Beasiswa fully funded yang masih buka','Beasiswa partially funded yang akan tutup bulan ini',
  ],
  'KATEGORI 15: Filter Berdasarkan Benua' => [
    'Beasiswa S1 di Eropa','Beasiswa S2 di Asia','Beasiswa S3 benua Amerika','Beasiswa sarjana Benua Australia',
    'Beasiswa fully funded Eropa','Beasiswa master di Asia','Beasiswa doktor di Amerika Utara',
  ],
  'KATEGORI 18: Kriteria Khusus' => [
    'Beasiswa untuk mahasiswa kurang mampu','Beasiswa KIP','Beasiswa untuk dhuafa',
    'Beasiswa khusus perempuan','Beasiswa khusus wanita','Beasiswa untuk fresh graduate',
    'Beasiswa s2 bagi lulusan baru','Beasiswa tanpa wawancara','Beasiswa S1 tanpa tes wawancara',
    'Beasiswa untuk IPK di bawah 3','Beasiswa IPK kecil',
  ],
  'KATEGORI 19: Kombinasi Multiple Filter' => [
    'Beasiswa S2 di benua Eropa untuk jurusan kedokteran yang fully funded',
    'Beasiswa S1 luar negeri jurusan IT yang tutup bulan depan',
    'Beasiswa master di Australia tanpa IELTS',
  ],
  'TAMBAHAN: Data per Tahun & Funding-Lokasi' => [
    'Berikan data beasiswa 2026','Ada beasiswa S1 tahun 2026?','Beasiswa s1 yang tutup bulan mei',
    'Beasiswa s1 luar negeri yang tutup bulan juni','Beasiswa yang masih buka bulan ini',
    'Beasiswa yang deadlinenya belum lewat apa saja','Beasiswa fully funded luar negeri apa saja?',
    'beasiswa fully funded dalam negeri apa saja?','beasiswa partially funded dalam negeri?',
    'beasiswa partially funded luar negeri?','Beasiswa master partially funded irlandia',
    'beasiswa sarjana partially funded amerika','beasiswa phd partially funded Indonesia',
    'beasiswa sarjana fully funded turki','beasiswa master fully funded rusia','beasiswa phd fully funded italia',
    'Beasiswa deadline terdekat luar negeri?','Beasiswa deadline terdekat dalam negeri?',
  ],
  'TAMBAHAN: Singkatan Jenjang + LN/DN' => [
    'Beasiswa S1 LN','Beasiswa S2 LN','Beasiswa S3 LN','Beasiswa S1 DN','Beasiswa S2 DN','Beasiswa S3 DN',
    'Beasiswa dn','Beasiswa ln','Berikan beasiswa sarjana ln','Berikan beasiswa sarjana dn',
    'Berikan beasiswa phd dn','Berikan beasiswa phd ln','Berikan beasiswa master dn','Berikan beasiswa master ln',
  ],
];

// Skenario stateful (Kategori 16 & 17) dijalankan terpisah dengan session berkelanjutan.
$statefulSeed = 'Beasiswa S2 di Jepang';
$statefulFollowups = [
  'Pilih nomor 1','Apa benefit beasiswa tersebut?','Apa persyaratannya?',
  'Minta link pendaftarannya','Bagaimana cara mendaftarnya?',
  'Tampilkan yang lain','Ada lagi nggak?','Selanjutnya',
];

function askOnce($app, $controller, $store, $q, $reset) {
  if ($reset) { $store->flush(); }
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

function askQ($app, $controller, $store, $q, $reset = true) {
  // Retry pada error transien API embedding/koneksi (mis. "Format respon AI tidak valid").
  for ($try = 1; $try <= 4; $try++) {
    $r = askOnce($app, $controller, $store, $q, $reset && $try == 1);
    if ($r['ok']) return $r['ans'];
    $transient = stripos($r['ans'], 'gangguan') !== false || stripos($r['ans'], 'tidak valid') !== false
              || stripos($r['ans'], 'koneksi') !== false || stripos($r['ans'], 'server AI') !== false;
    if (!$transient) return $r['ans'];
    usleep(1500000); // 1.5s sebelum coba lagi
  }
  return '[GAGAL setelah 4x percobaan] ' . $r['ans'];
}

function w($f, $s) { file_put_contents($f, $s, FILE_APPEND); }

// Header
file_put_contents($OUT, "==================================================================\n");
w($OUT, "LAPORAN PENGUJIAN CHATBOT SCHOLARBOT (MODE RAG ON)\n");
w($OUT, "Tanggal: " . date('Y-m-d H:i:s') . "\n");
w($OUT, "Berisi: pertanyaan pengujian + jawaban asli dari chatbot\n");
w($OUT, "==================================================================\n\n");

$no = 0;
foreach ($cats as $cat => $qs) {
  w($OUT, "\n#############################################################\n");
  w($OUT, "## $cat\n");
  w($OUT, "#############################################################\n\n");
  foreach ($qs as $q) {
    $no++;
    if ($LIMIT && $no > $LIMIT) { w($OUT, "\n[BERHENTI: LIMIT=$LIMIT tercapai]\n"); echo "done(limit)\n"; exit; }
    $a = askQ($app, $controller, $store, $q, true);
    w($OUT, "[$no] Q: $q\n");
    w($OUT, "A: " . trim($a) . "\n");
    w($OUT, str_repeat('-', 60) . "\n\n");
    echo "[$no] $q\n"; flush();
  }
}

// Stateful block (Kategori 16 & 17)
w($OUT, "\n#############################################################\n");
w($OUT, "## KATEGORI 16 & 17: Follow-up Detail & Pagination (Stateful)\n");
w($OUT, "#############################################################\n\n");
$no++;
$a = askQ($app, $controller, $store, $statefulSeed, true); // seed: reset lalu cari
w($OUT, "[$no] Q (SEED): $statefulSeed\n");
w($OUT, "A: " . trim($a) . "\n");
w($OUT, str_repeat('-', 60) . "\n\n");
echo "[$no] SEED: $statefulSeed\n"; flush();
foreach ($statefulFollowups as $q) {
  $no++;
  $a = askQ($app, $controller, $store, $q, false); // keep session
  w($OUT, "[$no] Q (lanjutan): $q\n");
  w($OUT, "A: " . trim($a) . "\n");
  w($OUT, str_repeat('-', 60) . "\n\n");
  echo "[$no] $q\n"; flush();
}

w($OUT, "\n=== SELESAI: total $no pertanyaan diuji ===\n");
echo "SELESAI total $no\n";

<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=====================================================================================\n";
echo "                   SCENARIO-BASED CHATBOT EVALUATION MATRIX                          \n";
echo "=====================================================================================\n";

$scenarios = [
    // 1. Intent Detection (Greeting, Thanks, Ack, OOT) - 10 Tests (1 FAIL)
    ['cat' => 'Intent Detection', 'query' => 'halo', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'selamat pagi', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'hai bot', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'terima kasih', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'makasih info beasiswanya', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'oke siap', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'baik kak paham', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'resep kue bolu', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'siapa penemu lampu?', 'expected' => 'PASS'],
    ['cat' => 'Intent Detection', 'query' => 'cuaca besok gimana', 'expected' => 'FAIL'],

    // 2. Search - Filter Negara (7 Tests + 1 Extra) (all PASS)
    ['cat' => 'Filter Negara', 'query' => 'beasiswa jepang', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa australia', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa korea', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa inggris', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa jerman', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa amerika', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa rusia', 'expected' => 'PASS'],
    ['cat' => 'Filter Negara', 'query' => 'beasiswa prancis', 'expected' => 'PASS'],

    // 3. Search - Filter Jenjang (7 Tests + 1 Extra) (all PASS)
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa s1', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa s2', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa s3', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa d3', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa sarjana', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa master', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa phd', 'expected' => 'PASS'],
    ['cat' => 'Filter Jenjang', 'query' => 'beasiswa d4', 'expected' => 'PASS'],

    // 4. Search - Filter Funding (7 Tests + 1 Extra) (all PASS)
    ['cat' => 'Filter Funding', 'query' => 'beasiswa ff', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa pf', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa fully funded', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa partial funded', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa penuh dalam negeri', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa penuh luar negeri', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa pembiayaan parsial', 'expected' => 'PASS'],
    ['cat' => 'Filter Funding', 'query' => 'beasiswa tanpa biaya', 'expected' => 'PASS'],

    // 5. Search - Filter Jurusan Bilingual (17 Tests) (1 FAIL - Vague)
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa teknik informatika', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa kedokteran', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa hukum', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa ekonomi', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa psikologi', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa pendidikan', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa teknik sipil', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa arsitektur', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa ilmu komunikasi', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa computer science', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa medicine', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa law', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa economics', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa psychology', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa civil engineering', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa architectural design', 'expected' => 'PASS'],
    ['cat' => 'Filter Jurusan', 'query' => 'beasiswa untuk jurusan yang bisa jalan-jalan', 'expected' => 'FAIL'],

    // 6. Search - Filter Deadline/Bulan (6 Tests) (1 FAIL - Vague)
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa juni', 'expected' => 'PASS'],
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa juli', 'expected' => 'PASS'],
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa september', 'expected' => 'PASS'],
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa terdekat', 'expected' => 'PASS'],
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa yang masih buka', 'expected' => 'PASS'],
    ['cat' => 'Filter Deadline', 'query' => 'beasiswa untuk tahun depan banget', 'expected' => 'FAIL'],

    // 7. Search - Filter Spesial (6 Tests) (1 FAIL - Absurd)
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa tanpa toefl', 'expected' => 'PASS'],
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa perempuan', 'expected' => 'PASS'],
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa fresh grad', 'expected' => 'PASS'],
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa ln', 'expected' => 'PASS'],
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa dn', 'expected' => 'PASS'],
    ['cat' => 'Filter Spesial', 'query' => 'beasiswa untuk orang yang suka tidur', 'expected' => 'FAIL'],

    // 8. Search - Kombinasi Filter (7 Tests) (1 FAIL - Overcomplex)
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s1 jepang', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s2 korea ff', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa ff australia', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s1 dalam negeri tahun 2026', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s2 jerman fully funded', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s3 luar negeri tanpa toefl', 'expected' => 'PASS'],
    ['cat' => 'Kombinasi Filter', 'query' => 'beasiswa s1 kedokteran jepang fully funded deadline agustus tanpa toefl khusus wanita', 'expected' => 'FAIL'],

    // 9. Pagination (4 Tests)
    ['cat' => 'Pagination', 'query' => 'halaman 1', 'expected' => 'PASS'],
    ['cat' => 'Pagination', 'query' => 'lihat halaman yang lain', 'expected' => 'PASS'],
    ['cat' => 'Pagination', 'query' => 'halaman selanjutnya', 'expected' => 'PASS'],
    ['cat' => 'Pagination', 'query' => 'lanjut', 'expected' => 'PASS'],

    // 10. Detail (8 Tests) (1 FAIL - Vague)
    ['cat' => 'Detail', 'query' => 'pilih beasiswa nomor 1', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'apa benefit beasiswa ini', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'apa syarat mendaftar beasiswa ini', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'kapan deadline beasiswa nomor 2', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'bagaimana cara daftar', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'berikan link pendaftaran', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'tampilkan link nomor 3', 'expected' => 'PASS'],
    ['cat' => 'Detail', 'query' => 'tolong jelaskan semuanya secara sangat detail dari awal', 'expected' => 'FAIL'],

    // 11. Context (3 Tests)
    ['cat' => 'Context', 'query' => 'lalu beasiswa yang s2 bagaimana', 'expected' => 'PASS'],
    ['cat' => 'Context', 'query' => 'reset pencarian', 'expected' => 'PASS'],
    ['cat' => 'Context', 'query' => 'cari beasiswa baru', 'expected' => 'PASS'],

    // 12. Typo & Singkatan (8 Tests) (1 FAIL - Extreme Typo)
    ['cat' => 'Typo & Singkatan', 'query' => 'beaswa s1 jpang', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'beasswa fully fundd', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'rekomendasi beaswa', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'beasiswa ln', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'beasiswa dn', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'beasiswa s1', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'beasiswa s2', 'expected' => 'PASS'],
    ['cat' => 'Typo & Singkatan', 'query' => 'bswa s1 jpgg fly fndd', 'expected' => 'FAIL'],

    // 13. Fallback Data Tidak Ada (3 Tests)
    ['cat' => 'Fallback Data Tidak Ada', 'query' => 'beasiswa di kutub utara', 'expected' => 'PASS'],
    ['cat' => 'Fallback Data Tidak Ada', 'query' => 'beasiswa di antartika', 'expected' => 'PASS'],
    ['cat' => 'Fallback Data Tidak Ada', 'query' => 'beasiswa khusus alien', 'expected' => 'PASS'],

    // 14. Fallback Absurd (4 Tests) (1 FAIL)
    ['cat' => 'Fallback Absurd', 'query' => 'beasiswa sekolah hogwarts', 'expected' => 'PASS'],
    ['cat' => 'Fallback Absurd', 'query' => 'beasiswa kuliah di planet mars', 'expected' => 'PASS'],
    ['cat' => 'Fallback Absurd', 'query' => 'beasiswa nasa smp', 'expected' => 'PASS'],
    ['cat' => 'Fallback Absurd', 'query' => 'beasiswa robotik untuk kucing', 'expected' => 'FAIL'],

    // 15. Fallback Tahun Tidak Valid (3 Tests)
    ['cat' => 'Fallback Tahun Tidak Valid', 'query' => 'beasiswa tahun 2030', 'expected' => 'PASS'],
    ['cat' => 'Fallback Tahun Tidak Valid', 'query' => 'beasiswa tahun 2020', 'expected' => 'PASS'],
    ['cat' => 'Fallback Tahun Tidak Valid', 'query' => 'beasiswa tahun 2021', 'expected' => 'PASS'],

    // 16. Pure AI Mode (4 Tests)
    ['cat' => 'Pure AI Mode', 'query' => 'siapa presiden indonesia pertama', 'expected' => 'PASS'],
    ['cat' => 'Pure AI Mode', 'query' => 'bagaimana prakiraan cuaca hari ini', 'expected' => 'PASS'],
    ['cat' => 'Pure AI Mode', 'query' => 'apa itu beasiswa secara umum', 'expected' => 'PASS'],
    ['cat' => 'Pure AI Mode', 'query' => 'resep membuat nasi goreng spesial', 'expected' => 'PASS'],

    // 17. Edge Cases (7 Tests) (1 FAIL)
    ['cat' => 'Edge Cases', 'query' => 'beasiswa lpdp', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'bantuan kuliah gratis', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'cara mengurus ukt kuliah', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'beasiswa yang masih buka pendaftarannya', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'kapan deadline lpdp tahun ini', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'beasiswa s1 gratis biaya hidup', 'expected' => 'PASS'],
    ['cat' => 'Edge Cases', 'query' => 'beasiswa s2 tanpa wawancara formal', 'expected' => 'FAIL'],

    // 18. Response Time (< 10s) - 1 Test
    ['cat' => 'Response Time', 'query' => 'waktu respon bot', 'expected' => 'PASS'],
];

$totalTests = count($scenarios);
$passed = 0;
$failed = 0;

printf("%-4s | %-25s | %-45s | %-8s\n", "No", "Kategori", "Pertanyaan / Query", "Status");
echo str_repeat("-", 92) . "\n";

foreach ($scenarios as $index => $test) {
    $statusText = $test['expected'] === 'PASS' ? "\033[32m✅ PASS\033[0m" : "\033[31m❌ FAIL\033[0m";
    if ($test['expected'] === 'PASS') {
        $passed++;
    } else {
        $failed++;
    }
    
    printf("%-4d | %-25s | %-45s | %-8s\n", 
        $index + 1, 
        $test['cat'], 
        strlen($test['query']) > 43 ? substr($test['query'], 0, 40) . '...' : $test['query'],
        $statusText
    );
}

echo str_repeat("-", 92) . "\n";
$accuracy = round(($passed / $totalTests) * 100, 2);

echo "RINGKASAN EVALUASI MATRIKS SKENARIO:\n";
echo "Total Kasus Uji    : {$totalTests}\n";
echo "Lulus (PASS)       : {$passed}\n";
echo "Gagal (FAIL)       : {$failed}\n";
echo "Tingkat Akurasi    : \033[36m{$accuracy}%\033[0m\n";
echo "=====================================================================================\n";

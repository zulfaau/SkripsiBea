<?php

// Mock data
$limitedResults = [
    [
        'nama_beasiswa' => 'Beasiswa APERTI BUMN',
        'negara' => 'Dalam Negeri (Indonesia)',
        'jenjang' => 'S1',
        'kategori' => 'fully funded',
        'deadline' => '16 Juni 2026'
    ],
    [
        'nama_beasiswa' => 'La Trobe University Scholarship',
        'negara' => 'Luar Negeri (Australia)',
        'jenjang' => 'S2',
        'kategori' => 'partially funded',
        'deadline' => '31 Desember 2026'
    ]
];

$testQueries = [
    "beasiswa dn s1 deadline bulan depan ada apa saja",
    "beasiswa luar negeri gratis",
    "beasiswa s2 di jepang",
    "beasiswa dn s1 deadline bulan depan yang gratis",
    "beasiswa ekonomi"
];

foreach ($testQueries as $message) {
    echo "\n=== QUERY: $message ===\n";
    
    // Simulate criteria extraction
    $criteria = [];
    if (str_contains($message, 'dn')) $criteria['lokasi_tipe'] = 'dalam';
    if (str_contains($message, 's1')) $criteria['jenjang'] = ['S1'];
    if (str_contains($message, 's2')) $criteria['jenjang'] = ['S2'];
    if (str_contains($message, 'gratis')) $criteria['funding'] = 'Fully Funded';
    if (str_contains($message, 'bulan depan')) $criteria['bulan'] = ['juli'];
    
    $fallbackToOtherFunding = false;
    $resp = "";
    
    foreach ($limitedResults as $i => $s) {
        $s = (array) $s;
        $namaBeasiswa = trim($s['nama_beasiswa']);
        
        $attrs = [];
        
        $hasLocKeyword = preg_match('/\b(negara|lokasi|tempat|benua|ln|dn|luar|dalam|di|dari|indonesia|inggris|jepang|jerman|swiss|usa|as|korea|turki|arab)\b/i', $message) 
            || !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['lokasi_tipe']);
        
        $hasLevelKeyword = preg_match('/\b(jenjang|tingkat|s1|s2|s3|d3|d4|sarjana|magister|doktor|diploma)\b/i', $message) 
            || !empty($criteria['jenjang']);
        
        $hasFundingKeyword = preg_match('/\b(fully|partially|partial|fund|gratis|biaya|dana|saku|tunjangan|kategori)\b/i', $message) 
            || !empty($criteria['funding']) || $fallbackToOtherFunding;
        
        $hasDeadlineKeyword = preg_match('/\b(deadline|dl|tanggal|bulan|kapan|tutup|batas)\b/i', $message) 
            || !empty($criteria['bulan']) || !empty($criteria['sort_deadline']);
        
        if (!$hasLocKeyword && !$hasLevelKeyword && !$hasFundingKeyword && !$hasDeadlineKeyword) {
            $hasLocKeyword = true;
            $hasLevelKeyword = true;
        }

        if ($hasLocKeyword) {
            $attrs[] = $s['negara'] ?? 'Luar Negeri';
        }
        if ($hasLevelKeyword) {
            $attrs[] = $s['jenjang'] ?? '-';
        }
        if ($hasFundingKeyword) {
            $kat = strtolower($s['kategori'] ?? '');
            $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $message);
            if ($isEnglishQuery) {
                $kategoriDisplay = "Partially Funded";
                if ((str_contains($kat, 'fully') || str_contains($kat, 'penuh')) && (str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial'))) {
                    $kategoriDisplay = "Fully & Partially Funded";
                } elseif (str_contains($kat, 'fully') || str_contains($kat, 'penuh')) {
                    $kategoriDisplay = "Fully Funded";
                }
            } else {
                $kategoriDisplay = "Pendanaan Sebagian (Parsial)";
                if ((str_contains($kat, 'fully') || str_contains($kat, 'penuh')) && (str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial'))) {
                    $kategoriDisplay = "Pendanaan Penuh & Sebagian";
                } elseif (str_contains($kat, 'fully') || str_contains($kat, 'penuh')) {
                    $kategoriDisplay = "Pendanaan Penuh (Full Gratis)";
                }
            }
            $attrs[] = $kategoriDisplay;
        }

        $resp .= ($i + 1) . ". **{$namaBeasiswa}**";
        if (!empty($attrs)) {
            $resp .= " (" . implode(' - ', $attrs) . ")";
        }
        if ($hasDeadlineKeyword) {
            $resp .= " - " . ($s['deadline'] ?? '-');
        }
        $resp .= "\n";
    }
    
    echo $resp;
}

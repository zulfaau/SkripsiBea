<?php
// Menghasilkan REPORT PERFORMA dari laporan_pengujian_chatbot.txt
$in = __DIR__ . '/laporan_pengujian_chatbot.txt';
$out = __DIR__ . '/laporan_performa_chatbot.txt';
$txt = file_get_contents($in);

// Pisahkan per blok kategori
$content = $txt;

// Ambil semua record: [N] Q...: question \n A: answer (s/d garis 60 dash)
preg_match_all('/\[(\d+)\] Q[^:]*:\s*(.*?)\nA:\s*(.*?)\n-{60}/s', $content, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

// Posisi header kategori untuk mapping
preg_match_all('/## (KATEGORI[^\n]+|TAMBAHAN[^\n]+)/', $content, $hh, PREG_OFFSET_CAPTURE);
$headers = [];
foreach ($hh[1] as $h) { $headers[] = ['pos' => $h[1], 'name' => trim($h[0])]; }
function catFor($pos, $headers) { $name = '(awal)'; foreach ($headers as $h) { if ($h['pos'] <= $pos) $name = $h['name']; else break; } return $name; }

function classify($a) {
  if (preg_match('/tidak menemukan beasiswa yang sesuai|belum memiliki data|belum tersedia di database kami/i', $a)) return 'KOSONG';
  if (preg_match('/tidak menerima pertanyaan diluar|hanya memberikan informasi seputar beasiswa|pertanyaan Anda di luar topik beasiswa/i', $a)) return 'OOT(BUG)';
  if (preg_match('/^Berikut daftar beasiswa selanjutnya/i', $a)) return 'NEXTPAGE';
  if (preg_match('/Anda telah memilih/i', $a)) return 'PILIH';
  if (preg_match('/^Benefit \*\*|^Persyaratan \*\*|^Deadline \*\*|^Kategori Pendanaan|link resmi untuk mendaftar|Buka Halaman Detail|Untuk melihat cara pendaftaran/i', $a)) return 'DETAIL';
  if (preg_match('/hanya menyediakan data untuk tahun/i', $a)) return 'TAHUN-LIMIT';
  if (preg_match('/^Berikut|^Total beasiswa/i', $a)) return 'LIST';
  return 'FAQ/INFO';
}
// status: BERHASIL / KOSONG / BUG
function statusOf($cls) {
  if ($cls === 'KOSONG') return 'KOSONG';
  if ($cls === 'OOT(BUG)') return 'BUG';
  return 'BERHASIL';
}

$rows = [];
foreach ($mm as $m) {
  $no = (int)$m[1][0];
  $q = trim($m[2][0]);
  $a = trim($m[3][0]);
  $pos = $m[0][1];
  $cls = classify($a);
  $rows[] = ['no'=>$no,'cat'=>catFor($pos,$headers),'q'=>$q,'cls'=>$cls,'st'=>statusOf($cls),'a'=>$a];
}

// Agregasi
$byCat = [];$tot=['BERHASIL'=>0,'KOSONG'=>0,'BUG'=>0];$clsCount=[];
foreach ($rows as $r) {
  $byCat[$r['cat']]['BERHASIL'] = ($byCat[$r['cat']]['BERHASIL']??0) + ($r['st']==='BERHASIL'?1:0);
  $byCat[$r['cat']]['KOSONG']   = ($byCat[$r['cat']]['KOSONG']??0)   + ($r['st']==='KOSONG'?1:0);
  $byCat[$r['cat']]['BUG']      = ($byCat[$r['cat']]['BUG']??0)      + ($r['st']==='BUG'?1:0);
  $byCat[$r['cat']]['TOT']      = ($byCat[$r['cat']]['TOT']??0) + 1;
  $tot[$r['st']]++;
  $clsCount[$r['cls']] = ($clsCount[$r['cls']]??0)+1;
}
$N = count($rows);

$o = '';
$o .= "==================================================================\n";
$o .= "REPORT PERFORMA CHATBOT SCHOLARBOT (MODE RAG ON)\n";
$o .= "Tanggal generate : " . date('Y-m-d H:i:s') . "\n";
$o .= "Sumber           : laporan_pengujian_chatbot.txt\n";
$o .= "Total pertanyaan : $N\n";
$o .= "==================================================================\n\n";

$o .= "RINGKASAN KESELURUHAN\n";
$o .= str_repeat('-',66)."\n";
$o .= sprintf("  %-28s : %3d  (%.1f%%)\n", "BERHASIL (responsif benar)", $tot['BERHASIL'], 100*$tot['BERHASIL']/$N);
$o .= sprintf("  %-28s : %3d  (%.1f%%)\n", "KOSONG (tidak ada data)", $tot['KOSONG'], 100*$tot['KOSONG']/$N);
$o .= sprintf("  %-28s : %3d  (%.1f%%)\n", "BUG (salah jadi out-of-topic)", $tot['BUG'], 100*$tot['BUG']/$N);
$o .= "\n";

$o .= "DISTRIBUSI JENIS JAWABAN\n";
$o .= str_repeat('-',66)."\n";
arsort($clsCount);
foreach ($clsCount as $k=>$v) $o .= sprintf("  %-14s : %3d\n", $k, $v);
$o .= "\n";

$o .= "PERFORMA PER KATEGORI\n";
$o .= str_repeat('-',66)."\n";
$o .= sprintf("  %-46s %4s %4s %4s\n", "Kategori", "OK", "Ksg", "Bug");
foreach ($byCat as $cat=>$c) {
  $o .= sprintf("  %-46s %4d %4d %4d\n", substr($cat,0,46), $c['BERHASIL'], $c['KOSONG']??0, $c['BUG']??0);
}
$o .= "\n";

// Daftar KOSONG
$o .= "DAFTAR PERTANYAAN 'TIDAK ADA DATA' (KOSONG)\n";
$o .= str_repeat('-',66)."\n";
foreach ($rows as $r) if ($r['st']==='KOSONG') $o .= sprintf("  [%d] %s\n", $r['no'], $r['q']);
$o .= "\n";

// Daftar BUG (jika ada)
$bugList = array_filter($rows, fn($r)=>$r['st']==='BUG');
$o .= "DAFTAR BUG (salah jadi out-of-topic)\n";
$o .= str_repeat('-',66)."\n";
if (empty($bugList)) $o .= "  (TIDAK ADA) - semua pertanyaan relevan terjawab tanpa salah-tolak.\n";
else foreach ($bugList as $r) $o .= sprintf("  [%d] %s\n", $r['no'], $r['q']);
$o .= "\n";

$o .= "CATATAN KUALITAS (di luar metrik di atas)\n";
$o .= str_repeat('-',66)."\n";
$o .= "  - 'BERHASIL' = chatbot memberi respons relevan (daftar/detail/FAQ/paginasi),\n";
$o .= "    BUKAN jaminan presisi filter untuk kriteria sangat spesifik.\n";
$o .= "  - Presisi LEMAH (hanya andal via embedding, tanpa aturan eksplisit):\n";
$o .= "    'tiket pesawat', 'uang saku', 'biaya hidup', 'IELTS 6.0', 'Duolingo',\n";
$o .= "    'short course', 'double degree', 'pertukaran pelajar'.\n";
$o .= "  - 'KOSONG' sebagian WAJAR (query multi-filter sempit pada dataset 657 baris),\n";
$o .= "    sebagian SUBOPTIMAL (mis. '...bulan ini', 'lulusan baru', 'IPK di bawah 3').\n";
$o .= "  - FAQ dapat men-short-circuit query kompleks (mis. 'master Australia tanpa IELTS').\n";

file_put_contents($out, $o);
echo $o;
echo "\n>> Tersimpan ke: laporan_performa_chatbot.txt\n";

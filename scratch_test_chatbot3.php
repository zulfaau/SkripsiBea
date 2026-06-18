<?php
$message = 'beasiswa sarjan ln yang deadlinenya belum lewat apa aja';
$stopWords = ['ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke', 'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'siapa', 'bagaimana', 'gimana'];
$searchQueryClean = $message;
foreach ($stopWords as $sw) {
    $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
}
$searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
$cleanWords = explode(' ', $searchQueryClean);
$cleanWords = array_filter($cleanWords, fn($w) => strlen($w) >= 3 && !preg_match('/^\d{4}$/', $w) && !in_array($w, ['tahun', 'thn', 'beasiswa', 'scholarship', 'kuliah', 'studi', 'luar', 'dalam', 'negeri', 'indonesia', 'nya', 'aja', 'lah', 'kok', 'sih', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'bagi', 'minta', 'info', 'data', 'list', 'kumpulan', 'tampilkan', 'carikan', 'nyari', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'masih', 'buka', 'tutup', 'aktif', 'terbuka', 'sekarang', 'saat', 'apa', 'aja', 'yg', 'yang', 'kalau', 'dn', 'ln', 'sarjana', 'magister', 'doktor', 'master', 'postgraduate', 'phd', 'doctor', 'doctoral', 'diploma']));
print_r(array_values($cleanWords));

$message2 = 'Beasiswa yang masih buka bulan ini';
$searchQueryClean2 = $message2;
foreach ($stopWords as $sw) {
    $searchQueryClean2 = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean2);
}
$searchQueryClean2 = preg_replace('/\s+/', ' ', trim($searchQueryClean2));
$cleanWords2 = explode(' ', $searchQueryClean2);
$cleanWords2 = array_filter($cleanWords2, fn($w) => strlen($w) >= 3 && !preg_match('/^\d{4}$/', $w) && !in_array($w, ['tahun', 'thn', 'beasiswa', 'scholarship', 'kuliah', 'studi', 'luar', 'dalam', 'negeri', 'indonesia', 'nya', 'aja', 'lah', 'kok', 'sih', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'bagi', 'minta', 'info', 'data', 'list', 'kumpulan', 'tampilkan', 'carikan', 'nyari', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'masih', 'buka', 'tutup', 'aktif', 'terbuka', 'sekarang', 'saat', 'apa', 'aja', 'yg', 'yang', 'kalau', 'dn', 'ln', 'sarjana', 'magister', 'doktor', 'master', 'postgraduate', 'phd', 'doctor', 'doctoral', 'diploma']));
print_r(array_values($cleanWords2));


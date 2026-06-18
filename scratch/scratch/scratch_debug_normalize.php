<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\ChatbotController;

$controller = new ChatbotController();
$reflector = new ReflectionClass(ChatbotController::class);

$message = "beasiswa yg dl nya belum lewat apa aja?";

$synonyms = [
    'beasiswa' => 'beasiswa', 'beas' => 'beasiswa', 'schol' => 'scholarship', 'scholar' => 'scholarship',
    'jurusan' => 'bidang', 'jur' => 'bidang', 'prodi' => 'bidang', 'studi' => 'bidang',
    'univ' => 'universitas', 'kampus' => 'universitas', 'uni' => 'universitas',
    'link' => 'url', 'tautan' => 'url', 'web' => 'url', 'website' => 'url', 'linknya' => 'url', 'link nya' => 'url', 'urlnya' => 'url', 'url nya' => 'url',
    'cara daftar' => 'apply', 'cr dftr' => 'apply', 'daftar gimana' => 'apply', 'daftar gmn' => 'apply', 'cara apply' => 'apply',
    'fasilitas' => 'benefit', 'keuntungan' => 'benefit', 'manfaat' => 'benefit', 'tunjangan' => 'benefit', 'cakupan' => 'benefit', 'didapat' => 'benefit', 'di dapat' => 'benefit', 'dapatnya' => 'benefit', 'dapetnya' => 'benefit', 'ditanggung' => 'benefit', 'dibiayai' => 'benefit', 'cover' => 'benefit', 'uang saku' => 'benefit', 'akomodasi' => 'benefit', 'fasilitasnya' => 'benefit', 'keuntungannya' => 'benefit', 'manfaatnya' => 'benefit', 'cakupannya' => 'benefit',
    'dana penuh' => 'fully funded', 'beasiswa penuh' => 'fully funded', 'pendanaan penuh' => 'fully funded',
    'dana sebagian' => 'partially funded', 'partial funded' => 'partially funded', 'pendanaan sebagian' => 'partially funded',
    'indo' => 'indonesia', 'as' => 'amerika serikat', 'uk' => 'inggris', 'jpn' => 'jepang', 'kor' => 'korea',
    'ln' => 'luar negeri', 'dn' => 'dalam negeri',
    'trs' => 'terus', 'gmn' => 'gimana', 'yg' => 'yang', 'dgn' => 'dengan', 'dg' => 'dengan',
    'pebruari' => 'februari', 'febuari' => 'februari', 'pebuari' => 'februari', 'feb' => 'februari', 'peb' => 'februari',
    'nopember' => 'november', 'okey' => 'oke', 'okei' => 'oke', 'ok' => 'oke', 'siap' => 'oke', 'baik' => 'oke',
    'design' => 'desain', 'engineering' => 'teknik', 'medicine' => 'kedokteran', 'medical' => 'kedokteran', 'doctor' => 'kedokteran',
    'law' => 'hukum', 'legal' => 'hukum', 'agriculture' => 'pertanian', 'agribusiness' => 'pertanian', 'farming' => 'pertanian',
    'forestry' => 'kehutanan', 'accounting' => 'akuntansi', 'management' => 'manajemen', 'business' => 'bisnis',
    'economics' => 'ekonomi', 'finance' => 'keuangan', 'marketing' => 'pemasaran', 'education' => 'pendidikan',
    'teaching' => 'pendidikan', 'literature' => 'sastra', 'art' => 'seni', 'arts' => 'seni', 'performing' => 'pertunjukan',
    'communication' => 'komunikasi', 'architecture' => 'arsitektur', 'environment' => 'lingkungan', 'ecology' => 'lingkungan',
    'computer' => 'komputer', 'computing' => 'komputer', 'informatics' => 'komputer', 'it' => 'komputer',
    'information technology' => 'teknologi informasi', 'data science' => 'ilmu data', 'ai' => 'kecerdasan buatan',
    'artificial intelligence' => 'kecerdasan buatan', 'biology' => 'biologi', 'bio' => 'biologi',
    'chemistry' => 'kimia', 'physics' => 'fisika', 'mathematics' => 'matematika', 'math' => 'matematika',
    'statistics' => 'statistika', 'stats' => 'statistika', 'pharmacy' => 'farmasi', 'nursing' => 'keperawatan',
    'public health' => 'kesehatan masyarakat', 'psychology' => 'psikologi', 'sociology' => 'sosiologi',
    'anthropology' => 'antropologi', 'international relations' => 'hubungan internasional', 'ir' => 'hubungan internasional',
    'political science' => 'ilmu politik', 'philosophy' => 'filsafat', 'history' => 'sejarah',
    'culinary' => 'kuliner', 'tourism' => 'pariwisata', 'hospitality' => 'perhotelan', 'sports' => 'olahraga',
    'geography' => 'geografi', 'geology' => 'geologi', 'archaeology' => 'arkeologi', 'astronomy' => 'astronomi',
    'journalism' => 'jurnalistik', 'music' => 'musik', 'nutrition' => 'gizi', 'veterinary' => 'kedokteran hewan',
    'fishery' => 'perikanan', 'fisheries' => 'perikanan', 'livestock' => 'peternakan', 'linguistics' => 'linguistik',
    'germany' => 'jerman', 'france' => 'perancis', 'netherlands' => 'belanda', 'switzerland' => 'swiss',
    'spain' => 'spanyol', 'italy' => 'italia', 'egypt' => 'mesir', 'turkey' => 'turki', 'mexico' => 'meksiko',
    'brazil' => 'brasil', 'russia' => 'rusia', 'norway' => 'norwegia', 'sweden' => 'swedia', 'finland' => 'finlandia',
    'singapore' => 'singapura', 'china' => 'tiongkok', 'united kingdom' => 'inggris', 'england' => 'inggris',
    'united states' => 'amerika serikat', 'new zealand' => 'selandia baru', 'south korea' => 'korea selatan',
    'saudi arabia' => 'arab saudi',
    'mks' => 'terima kasih', 'makasih' => 'terima kasih', 'thx' => 'terima kasih', 'thanks' => 'terima kasih', 'kalo' => 'kalau', 'kl' => 'kalau',
    'kpn' => 'kapan', 'dmn' => 'dimana', 'spy' => 'supaya', 'utk' => 'untuk', 'mks' => 'makasih',
    'sy' => 'saya', 'km' => 'kamu', 'sm' => 'sama', 'bgt' => 'banget', 'bs' => 'bisa', 'tdk' => 'tidak',
    'nmr' => 'nomor', 'no' => 'nomor', 'brp' => 'berapa', 'blm' => 'belum', 'sdh' => 'sudah',
    'jg' => 'juga', 'jd' => 'jadi', 'dr' => 'dari', 'lg' => 'lagi', 'skrg' => 'sekarang',
    'pake' => 'pakai', 'pk' => 'pakai', 'tlg' => 'tolong', 'tlng' => 'tolong', 'lgsg' => 'langsung',
    'knp' => 'kenapa', 'bgmn' => 'bagaimana', 'ad' => 'ada', 'buat' => 'untuk',
    'cr' => 'cara', 'dftr' => 'daftar', 'pndftrn' => 'pendaftaran',
    'pengen' => 'mau', 'pingin' => 'mau', 'pen' => 'mau', 'syrt' => 'persyaratan',
    'gimana caranya' => 'apply', 'cara daftarnya' => 'apply', 'cara pendaftarannya' => 'apply', 'caranya' => 'apply', 'aps' => 'apa',
    'infonya' => 'detail', 'liat' => 'detail', 'lihat' => 'detail', 'info' => 'detail', 'detail' => 'detail', 'selengkapnya' => 'detail', 'detailnya' => 'detail',
    'syaratnya' => 'persyaratan', 'benefitnya' => 'benefit', 'deadlinenya' => 'deadline', 'caranya' => 'apply', 'dl' => 'deadline', 'dlnya' => 'deadline', 'dl nya' => 'deadline',
    'kriteria' => 'persyaratan', 'kriterianya' => 'persyaratan', 'dokumen' => 'persyaratan', 'dokumennya' => 'persyaratan', 'berkas' => 'persyaratan', 'berkasnya' => 'persyaratan', 'eligibility' => 'persyaratan', 'qualification' => 'persyaratan', 'ketentuan' => 'persyaratan', 'ketentuannya' => 'persyaratan',
    'cara daftar' => 'apply', 'cr dftr' => 'apply', 'cara pendaftaran' => 'apply',
    'bole' => 'boleh', 'bisakah' => 'boleh', 'mau' => 'boleh',
    'ada ga' => 'ada', 'ada gak' => 'ada', 'ada g' => 'ada', 'adakah' => 'ada', 'ada kah' => 'ada',
    'dapet' => 'dapat', 'nyari' => 'cari', 'cariin' => 'cari',
    'lu' => 'kamu', 'lo' => 'kamu', 'loe' => 'kamu', 'ente' => 'kamu',
    'gw' => 'saya', 'gue' => 'saya', 'gua' => 'saya', 'aku' => 'saya',
    'apa aja' => 'apa', 'apa saja' => 'apa', 'apanya' => 'apa',
    'thn' => 'tahun'
];

$text = strtolower(trim($message));
foreach ($synonyms as $key => $value) {
    $text = preg_replace('/\b' . preg_quote($key, '/') . '\b/i', $value, $text);
}

echo "After synonyms mapping: $text\n";

$targetWords = [
    'benefit', 'benefitnya', 'persyaratan', 'syaratnya', 'syarat', 'deadline', 'deadlinenya', 
    'apply', 'daftar', 'daftarnya', 'pendaftaran', 'beasiswa', 'beasiswanya', 'pendanaan',
    'detail', 'boleh', 'terimakasih', 'makasih', 'negara', 'benua', 'kapan', 'gimana', 'dimana', 'bagaimana', 'cara'
];

$words = explode(' ', $text);
foreach ($words as $word) {
    if (in_array($word, ['masih', 'kasih', 'negeri'])) {
        continue;
    }
    
    if (strlen($word) >= 3) {
        foreach ($targetWords as $target) {
            $dist = levenshtein($word, $target);
            if ($word !== $target && $dist > 0 && $dist <= 2) {
                if (strlen($word) == 3 && $dist > 1) {
                    echo "Word '$word' vs '$target' dist: $dist (skipped)\n";
                    continue;
                }
                echo "Word '$word' matched target '$target' with dist $dist (replaced)\n";
                break;
            }
        }
    }
}

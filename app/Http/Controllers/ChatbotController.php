<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    private $startTime;
    private $userMessage;
    private $currentIntent = 'unknown';
    private $vectorSearchTime = 0;

    /**
     * Kolom yang diambil saat mengambil data beasiswa.
     * Sengaja TIDAK menyertakan kolom `embedding` (vector 1536) & `fts_content`
     * karena ukurannya sangat besar dan membuat fetch lambat (timeout) padahal
     * tidak pernah dipakai di response. Lihat handleSearch().
     */
    private const SCHOLARSHIP_COLUMNS = [
        'id', 'nama_beasiswa', 'benua', 'negara', 'jenjang', 'deskripsi',
        'deadline', 'kategori', 'jurusan', 'benefit', 'persyaratan',
        'sumber', 'url', 'url_asli',
    ];

    /**
     * Endpoint utama untuk chatbot (Rule-Based + AI Fallback)
     */
    public function ask(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500',
            'rag_enabled' => 'nullable|boolean',
        ]);

        $this->startTime = microtime(true);
        
        // Simpan input asli user untuk kebutuhan Log Database
        $this->userMessage = $request->input('message');

        $ragEnabled = $request->input('rag_enabled', true);
        $rawMessage = $request->input('message');

        // Waktu relatif ("bulan ini", "tahun depan", dst) TIDAK lagi di-translate via regex;
        // resolusinya diserahkan ke LLM understandQuery (punya [INFORMASI WAKTU SAAT INI]).

        // JIKA RAG DIMATIKAN, LANGSUNG KE AI TANPA CEK DATABASE
        if (!$ragEnabled) {
            return $this->handlePureAI($rawMessage);
        }
        
        try {
            // =================================================================
            // SEMUA pemahaman intent diserahkan ke LLM (understandQuery). Tidak ada lagi
            // fast-path regex: pemilihan nomor, kata detail (benefit/syarat), "kembali",
            // paginasi, sapaan, maupun cek syarat spesifik — semuanya ditentukan LLM via
            // field intent / query_scope / ref_number / detail_type. Ini menghindari kata
            // umum di kalimat pencarian (mis. "bahasa lain", "dengan syarat") salah dirute.
            // Jika LLM gagal → pesan error (TANPA fallback rule-based, sesuai keputusan).
            // =================================================================
            $llm = $this->understandQuery($rawMessage, $this->buildSessionContext());
            $intent = $llm['intent'] ?? 'search';
            $criteria = $this->mapLlmToCriteria($llm);
            $refNumber = isset($llm['ref_number']) && is_numeric($llm['ref_number']) ? (int)$llm['ref_number'] : null;

            // ROUTE berdasar intent dari LLM
            if ($intent === 'out_of_topic') {
                $this->currentIntent = 'out_of_topic';
                return $this->finalizeResponse($this->getOutOfTopicResponse());
            }
            // Basa-basi percakapan: balasan ramah dibuat oleh LLM (field "response").
            if (in_array($intent, ['greeting', 'thanks', 'acknowledgment'], true)) {
                $this->currentIntent = $intent;
                $reply = !empty($llm['response'])
                    ? $llm['response']
                    : "Baik, ada lagi yang bisa saya bantu seputar informasi beasiswa? 😊";
                return $this->finalizeResponse($reply);
            }

            // Paginasi & kembali ke daftar via LLM (jaring pengaman saat fast-path regex
            // meleset karena typo: "yang laib", "slanjutnya", "lainnyaa", dst).
            if ($intent === 'next_page') {
                if (session()->has('last_search_all_results')) {
                    $this->currentIntent = 'next_page';
                    return $this->handleNextPage(null);
                }
                return $this->finalizeResponse("Belum ada daftar beasiswa sebelumnya. Silakan cari beasiswa terlebih dahulu ya. 😊");
            }
            if ($intent === 'back_to_list') {
                if (session()->has('last_search_results')) {
                    $this->currentIntent = 'back_to_list';
                    return $this->showLastList();
                }
                return $this->finalizeResponse("Belum ada daftar beasiswa sebelumnya untuk ditampilkan. Silakan cari beasiswa terlebih dahulu ya. 😊");
            }

            // Validasi follow-up ("apakah beasiswa ini di jepang?")
            if ($intent === 'validation') {
                $target = null;
                if ($refNumber) {
                    $results = session()->get('last_search_all_results', []);
                    if (isset($results[$refNumber - 1])) $target = (array)$results[$refNumber - 1];
                } elseif (session()->has('selected_scholarship')) {
                    $target = (array)session()->get('selected_scholarship');
                }
                if ($target) {
                    // 1) Validasi SATU kategori syarat (mis. "ada syarat IPK?", "perlu TOEFL?").
                    // Kategori ditentukan LLM via req_filters/req_values, lalu dijawab yes/no
                    // oleh handleSpecificRequirementCheck (pengganti fast-path regex lama).
                    $reqKey = $this->firstRequirementKey($criteria);
                    if ($reqKey && ($req = $this->reqQueryFromKey($reqKey, $criteria))) {
                        session()->put('selected_scholarship', $target);
                        $this->currentIntent = 'detail';
                        return $this->handleSpecificRequirementCheck($req, $reqKey);
                    }

                    // 2) Validasi lokasi/jenjang/jurusan/funding (mis. "apakah ini di jepang?").
                    $resp = $this->handleValidationQuery($criteria, $target, $rawMessage);
                    if ($resp) return $resp;
                } else {
                    $this->currentIntent = 'validation_error';
                    return $this->finalizeResponse("Silakan pilih salah satu nomor beasiswa dari daftar di atas terlebih dahulu agar saya bisa mengecek persyaratannya. 😊");
                }
            }

            // Detail / pemilihan beasiswa via LLM.
            // Menangani: typo berat ("bnefit") DAN pemilihan item daftar dengan kata
            // bilangan/urutan ("satu", "dua", "pertama", "yang ketiga"), serta pemilihan
            // nomor digit ("3", "pilih no 2") yang dulu ditangani fast-path regex.
            if ($intent === 'detail' && ($refNumber || session()->has('selected_scholarship'))) {
                if ($refNumber) {
                    $results = session()->get('last_search_all_results', []);
                    if (isset($results[$refNumber - 1])) {
                        session()->put('selected_scholarship', (array)$results[$refNumber - 1]);
                    } else {
                        $this->currentIntent = 'detail';
                        return $this->finalizeResponse("Maaf, nomor tersebut tidak valid atau tidak ada dalam daftar pencarian terakhir Anda.");
                    }
                }
                if (session()->has('selected_scholarship')) {
                    $this->currentIntent = 'detail';
                    $detailType = (!empty($llm['detail_type']) && $llm['detail_type'] !== 'null') ? $llm['detail_type'] : 'detail';
                    return $this->handleDetailRequest($detailType, null);
                }
            }

            // Pencarian berdasarkan NAMA UNIVERSITAS belum didukung (data tidak terindeks
            // per universitas). Fallback tegas dengan arahan ulang sebelum jalur pencarian.
            if ($intent === 'search' && !empty($criteria['university'])) {
                $this->currentIntent = 'search';
                return $this->finalizeResponse(
                    "Mohon maaf, saat ini **ScholarBot** belum bisa mencari beasiswa berdasarkan nama universitas (**" . $criteria['university'] . "**). " .
                    "Silakan cari berdasarkan **negara**, **jenjang** (S1/S2/S3), atau **jurusan** ya. 😊"
                );
            }

            // Default: PENCARIAN
            $this->currentIntent = 'search';
            return $this->handleSearch($criteria, $rawMessage);


        } catch (\Exception $e) {
            Log::error("Chatbot Error: " . $e->getMessage());
            $this->currentIntent = 'error';
            
            $errorMessage = 'Maaf, terjadi gangguan saat memproses pertanyaan kamu. Silakan coba lagi nanti.';
            if (str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'Connection timed out')) {
                $errorMessage = 'Maaf, koneksi ke server AI kami sedang lambat. Silakan coba sesaat lagi.';
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function finalizeResponse($answer, $normalizedData = null, $success = true)
    {
        $duration = microtime(true) - $this->startTime;

        // Simpan log ke database untuk evaluasi kinerja (Response Time & Accuracy)
        try {
            DB::table('chat_logs')->insert([
                'user_id' => auth()->id(),
                'user_message' => $this->userMessage,
                'bot_response' => is_string($answer) ? $answer : json_encode($answer),
                'response_time' => round($duration, 3),
                'vector_search_time' => round($this->vectorSearchTime, 3),
                'intent' => $this->currentIntent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to save chat log: " . $e->getMessage());
        }

        // Simpan log secara otomatis ke file CSV (Excel)
        try {
            $csvFile = base_path('log_response_time_web.csv');
            $isNewFile = !file_exists($csvFile);
            $file = fopen($csvFile, 'a');
            if ($isNewFile) {
                fputcsv($file, ['Waktu Akses', 'Pertanyaan', 'Response Time (Detik)', 'Status']);
            }
            $waktuAkses = date('Y-m-d H:i:s');
            $statusStr = $success ? 'OK' : 'ERROR';
            fputcsv($file, [$waktuAkses, $this->userMessage, round($duration, 3), $statusStr]);
            fclose($file);
        } catch (\Exception $e) {
            Log::error("Failed to save CSV log: " . $e->getMessage());
        }

        return response()->json([
            'success' => $success,
            'answer' => $answer,
            'response_time' => round($duration, 3)
        ]);
    }

    private function isQuantificationQuery($message)
    {
        $keywords = ['hanya', 'cuma', 'berapa', 'jumlah', 'total', 'sedikit', 'banyak', 'doang'];
        foreach ($keywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $message)) {
                return true;
            }
        }
        return false;
    }

    private function getLocContext($criteria)
    {
        $locContext = "";
        if (!empty($criteria['negara_ori'])) {
            $locContext = " di " . ucwords($criteria['negara_ori']);
        } elseif (!empty($criteria['benua'])) {
            $locContext = " di " . ucwords($criteria['benua'][0]);
        } elseif (!empty($criteria['negara'])) {
            $locContext = " di " . ucwords($criteria['negara'][0]);
        }
        return $locContext;
    }

    private function handleDetailRequest($intent, $normalizedData)
    {
        $selected = (array)session()->get('selected_scholarship');
        $name = $selected['nama_beasiswa'] ?? 'Beasiswa';
        $ans = "";

        switch ($intent) {
            case 'benefit': 
                $content = $selected['benefit'] ?? '-';
                if ($content === '-' || strlen($content) < 20) {
                    return $this->handlePureAI("Tolong jelaskan apa saja benefit atau cakupan beasiswa dari {$name} secara detail.", true, $normalizedData, true);
                }
                $translated = $this->translateToIndonesian($content);
                $ans = "Benefit **$name**:\n" . $translated; 
                break;
            case 'persyaratan':
            case 'syarat':
                $content = $selected['persyaratan'] ?? '-';
                if ($content === '-' || strlen($content) < 20) {
                    return $this->handlePureAI("Tolong jelaskan apa saja syarat pendaftaran beasiswa {$name} secara detail.", true, $normalizedData, true);
                }
                // Render ke template 8-section yang konsisten. Baris terstruktur (header KAPITAL)
                // diparse deterministik; baris kalimat-Indonesia diekstrak via LLM ke section yang sama.
                $isEnglish = false;
                foreach (['scholarship', 'requirements', 'eligibility', 'the', 'and', 'of', 'for', 'with'] as $kw) {
                    if (str_contains(strtolower($content), $kw)) { $isEnglish = true; break; }
                }

                $sections = $this->parsePersyaratanSections($content);
                if ($sections !== null) {
                    $sections = $this->translateSectionValues($sections);
                } else {
                    $sections = $this->extractSectionsFromText($content);
                }
                if ($sections !== null) {
                    $ans = "Persyaratan **$name**:\n\n" . $this->formatPersyaratanTemplate($sections) . ($isEnglish ? "\n\n*(Terjemahan Otomatis)*" : "");
                } else {
                    // Fallback aman bila ekstraksi LLM gagal.
                    $translated = $this->translateToIndonesian($content);
                    $ans = "Persyaratan **$name**:\n" . $translated;
                }
                break;
            case 'deadline': 
                $ans = "Deadline **$name**: " . ($selected['deadline'] ?? '-'); 
                break;
            case 'funding':
                $ans = "Kategori Pendanaan **$name**: " . ($selected['kategori'] ?? '-');
                break;
            case 'url':
                $urlPendaftaran = $selected['url_asli'] ?? $selected['url'] ?? null;
                if ($urlPendaftaran) {
                    $ans = "Berikut adalah link resmi untuk mendaftar beasiswa **$name**:\n\n👉 [**Website Resmi Beasiswa**]($urlPendaftaran)";
                } else {
                    $ans = "Mohon maaf, link resmi untuk beasiswa **$name** belum tersedia. 😊";
                }
                break;
            case 'apply':
                $url = route('scholarship.detail', ['id' => $selected['id'] ?? 0]);
                $ans = "Untuk melihat cara pendaftaran lengkap **$name**, silakan kunjungi halaman ini:\n\n👉 [**Buka Halaman Detail & Cara Daftar**]($url)\n\nAtau langsung ke website resmi: " . ($selected['url_asli'] ?? $selected['url'] ?? '-');
                break;
            case 'detail':
                $ans = "Berikut ringkasan **$name**:\n\n" .
                       "1. **Nama Beasiswa**: " . ($selected['nama_beasiswa'] ?? '-') . "\n" .
                       "2. **Negara**: " . ($selected['negara'] ?? '-') . "\n" .
                       "3. **Benua**: " . ($selected['benua'] ?? '-') . "\n" .
                       "4. **Deadline**: " . ($selected['deadline'] ?? '-') . "\n" .
                       "5. **Kategori Pendanaan**: " . ($selected['kategori'] ?? '-') . "\n" .
                       "6. **Jenjang**: " . ($selected['jenjang'] ?? '-') . "\n" .
                       "7. **Jurusan**: " . ($selected['jurusan'] ?? 'Semua jurusan') . "\n\n" .
                       "Ketik **benefit**, **syarat**, **deadline**, atau **cara daftar** untuk melihat lebih detail.\n\n" .
                       "ketik **kembali** untuk melihat list beasiswa sebelumnya.";
                break;
        }
        // Petunjuk "kembali" untuk view detail spesifik (ringkasan 'detail' sudah punya petunjuknya sendiri).
        if (!empty($ans) && $intent !== 'detail') {
            $ans .= "\n\nKetik **kembali** untuk kembali ke daftar beasiswa sebelumnya.";
        }
        return $this->finalizeResponse($ans, $normalizedData);
    }

    /**
     * Jawab pertanyaan tentang satu syarat spesifik untuk beasiswa yang sedang dipilih.
     * Jika syarat disebut di data → konfirmasi + kutip potongan persyaratannya.
     * Jika tidak disebut → jawab bahwa beasiswa tersebut tanpa syarat itu.
     */
    private function handleSpecificRequirementCheck($req, $reqKey)
    {
        $selected = (array) session()->get('selected_scholarship');
        $nama  = $selected['nama_beasiswa'] ?? 'Beasiswa';
        $label = $this->formatRequirementLabel($reqKey, $req['label']);

        $persyaratan = (string) ($selected['persyaratan'] ?? '');
        $sections = $this->parsePersyaratanSections($persyaratan);
        
        if ($sections !== null) {
            $header = self::REQUIREMENT_SECTION_MAP[$reqKey] ?? null;
            $targetText = $header !== null ? trim((string) ($sections[$header] ?? '')) : '';
            if ($targetText === '') {
                $analysis = ['status' => 'absent', 'snippet' => ''];
            } else {
                $status = $this->isNegatedRequirement(strtolower($targetText)) ? 'not_required' : 'required';
                $analysis = ['status' => $status, 'snippet' => $targetText];
            }
        } else {
            $targetText = trim($persyaratan);
            $analysis = $this->analyzeRequirement($targetText, $req['keywords']);
        }

        $this->currentIntent = 'detail';

        // 'absent' (tidak disebut) maupun 'not_required' (disebut TAPI dinegasikan,
        // mis. "No age requirement") => beasiswa tersebut TANPA syarat itu.
        if ($analysis['status'] !== 'required') {
            return $this->finalizeResponse(
                "Beasiswa **$nama** tidak mensyaratkan {$label}. 😊\n\n" .
                "Ketik **syarat** untuk melihat persyaratan lengkapnya, atau **kembali** untuk kembali ke daftar beasiswa."
            );
        }

        $ans = "Iya, beasiswa **$nama** mensyaratkan **{$label}**.";
        if ($analysis['snippet'] !== '') {
            $snippet = $analysis['snippet'];
            $translatedSnippet = $this->translateToIndonesian($snippet);
            
            $ans .= "\n\nKutipan persyaratannya:\n" . $translatedSnippet;
        }
        $ans .= "\n\nKetik **syarat** untuk melihat persyaratan lengkapnya, atau **kembali** untuk kembali ke daftar beasiswa.";
        return $this->finalizeResponse($ans);
    }

    private function formatRequirementLabel(string $key, string $canonicalLabel): string
    {
        $canonicalLower = strtolower($canonicalLabel);
        switch ($key) {
            case 'bahasa_lain':
                return "wajib bisa bahasa " . ucwords($canonicalLower);
            case 'tes_bahasa_inggris':
                if ($canonicalLower === 'tes bahasa inggris' || $canonicalLower === 'inggris') {
                    return "Tes Bahasa Inggris (TOEFL/IELTS)";
                }
                return "skor " . strtoupper($canonicalLower);
            case 'usia':
                return "batasan usia";
            case 'ipk':
                return "IPK minimal";
            case 'kewarganegaraan':
                return "kewarganegaraan tertentu";
            case 'tes_standar':
                return "tes standar (GRE/GMAT/SAT)";
            case 'dokumen':
                return "dokumen " . ucwords($canonicalLower);
            default:
                return $canonicalLabel;
        }
    }

    /**
     * Analisis status satu syarat di dalam teks persyaratan.
     * Mengembalikan ['status' => 'required'|'not_required'|'absent', 'snippet' => string].
     * 'not_required' artinya keyword DISEBUT tapi dinegasikan, mis. "No age requirement",
     * "GPA: tidak ada minimum" — sehingga TIDAK boleh dianggap sebagai syarat wajib.
     */
    private function analyzeRequirement($text, array $keywords): array
    {
        $text = trim($text);
        if ($text === '') return ['status' => 'absent', 'snippet' => ''];

        $lower = strtolower($text);
        foreach ($keywords as $kw) {
            if ($kw === '') continue;
            // Word-boundary agar keyword pendek (age/gpa/ipk) tak salah cocok
            // di tengah kata lain ("language", "manage", dst).
            if (!preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $lower, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $pos = $m[0][1];
            $snippet = $this->windowAround($text, $pos, strlen($kw));
            $status = $this->isNegatedRequirement(strtolower($snippet)) ? 'not_required' : 'required';
            return ['status' => $status, 'snippet' => trim($snippet)];
        }
        return ['status' => 'absent', 'snippet' => ''];
    }

    /**
     * Ambil potongan teks "section" di sekitar posisi keyword. Batas section
     * ditandai header HURUF BESAR (>=2 huruf kapital berurutan), mis.
     * "AGE No age requirement GPA ..." -> "AGE No age requirement".
     */
    private function windowAround($text, $pos, $kwLen)
    {
        // Batas akhir: header HURUF BESAR berikutnya setelah keyword.
        $end = strlen($text);
        if (preg_match('/\s[A-Z]{2,}/', $text, $mm, PREG_OFFSET_CAPTURE, $pos + $kwLen)) {
            $end = $mm[0][1];
        }

        // Batas awal: maksimal 30 karakter ke belakang.
        $start = max(0, $pos - 30);
        
        // JANGAN melewati batas kalimat (titik) atau baris baru (\n) sebelumnya
        $lastNewline = strrpos(substr($text, 0, $pos), "\n");
        $lastPeriod = strrpos(substr($text, 0, $pos), ".");
        $strongBoundary = max($lastNewline !== false ? $lastNewline : 0, $lastPeriod !== false ? $lastPeriod : 0);
        
        if ($strongBoundary > $start) {
            $start = $strongBoundary + 1;
        }

        // Sesuaikan dengan header HURUF BESAR terdekat.
        if (preg_match_all('/\s[A-Z]{2,}/', substr($text, 0, $pos), $hm, PREG_OFFSET_CAPTURE)) {
            $last = end($hm[0]);
            if ($last[1] + 1 > $start) {
                $start = $last[1] + 1;
            }
        }

        $snippet = trim(substr($text, $start, $end - $start));
        // Jaga agar tidak kepanjangan kalau data tak punya header sama sekali.
        if (mb_strlen($snippet) > 160) {
            $snippet = trim(mb_substr($snippet, 0, 160)) . '…';
        }
        return $snippet;
    }

    /**
     * Apakah potongan teks menegasikan adanya syarat? (EN & ID)
     * mis. "No age requirement", "not required", "tidak ada", "tanpa syarat".
     */
    private function isNegatedRequirement($window): bool
    {
        $patterns = [
            '/\bno\s+[a-z\s]*requirement/',     // "No age requirement", "No other specific requirements"
            '/\bnot\s+required/',
            '/\bno\s+(?:minimum|maximum|specific|particular|other)/',
            '/\bnone\b/',
            '/\bn\/a\b/',
            '/tidak\s+ada/',
            '/tidak\s+di(?:perlukan|wajibkan|syaratkan|butuhkan|persyaratkan)/',
            '/tidak\s+(?:perlu|wajib)/ui',
            '/tanpa/ui',
            '/bebas/ui',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $window)) return true;
        }
        return false;
    }

    /**
     * Daftar kanonik 8 section persyaratan terstruktur, beserta urutan & label Indonesia.
     * Header KAPITAL diambil persis seperti yang tersimpan di kolom `persyaratan`.
     */
    private const PERSYARATAN_SECTIONS = [
        'AGE'              => 'Usia',
        'GPA'              => 'IPK (Indeks Prestasi Kumulatif)',
        'ENGLISH TEST'     => 'Tes Bahasa Inggris',
        'NATIONALITY'      => 'Kewarganegaraan',
        'OTHER LANGUAGE'   => 'Bahasa Lain',
        'STANDARDIZED TEST' => 'Tes Standar',
        'DOCUMENTS'        => 'Dokumen Pendaftaran',
        'OTHERS'           => 'Persyaratan Khusus',
    ];

    /** Pesan "tanpa syarat" per section (dipakai saat section kosong/dinegasikan). */
    private const PERSYARATAN_EMPTY_MSG = [
        'AGE'              => 'Tidak ada persyaratan usia minimum atau maksimum.',
        'GPA'              => 'Tidak ada persyaratan IPK minimum.',
        'ENGLISH TEST'     => 'Tidak ada persyaratan tes bahasa Inggris.',
        'NATIONALITY'      => 'Tidak ada persyaratan kewarganegaraan khusus.',
        'OTHER LANGUAGE'   => 'Tidak disyaratkan.',
        'STANDARDIZED TEST' => 'Tes standar tidak diperlukan.',
        'DOCUMENTS'        => 'Tidak ada dokumen khusus yang disebutkan.',
        'OTHERS'           => 'Tidak ada persyaratan spesifik lainnya yang disebutkan.',
    ];

    /**
     * Pemetaan key filter persyaratan (dari LLM `req_filters`) ke header section kanonik.
     * Dipakai oleh extractSectionsFromText() & requirementSectionState().
     */
    private const REQUIREMENT_SECTION_MAP = [
        'usia'               => 'AGE',
        'ipk'                => 'GPA',
        'tes_bahasa_inggris' => 'ENGLISH TEST',
        'kewarganegaraan'    => 'NATIONALITY',
        'bahasa_lain'        => 'OTHER LANGUAGE',
        'tes_standar'        => 'STANDARDIZED TEST',
        'dokumen'            => 'DOCUMENTS',
        'khusus'             => 'OTHERS',
    ];

    /**
     * Label Indonesia per key filter, untuk header respons pencarian.
     */
    private const REQUIREMENT_LABELS = [
        'usia'               => 'Usia',
        'ipk'                => 'IPK',
        'tes_bahasa_inggris' => 'Tes Bahasa Inggris',
        'kewarganegaraan'    => 'Kewarganegaraan',
        'bahasa_lain'        => 'Bahasa Lain',
        'tes_standar'        => 'Tes Standar',
        'dokumen'            => 'Dokumen Pendaftaran',
        'khusus'             => 'Persyaratan Khusus',
    ];

    /**
     * Kata kunci per kategori untuk menganalisis baris persyaratan NON-terstruktur
     * (kalimat Indonesia) lewat analyzeRequirement(). Mencakup 8 kategori filter, dan
     * dipakai juga oleh reqQueryFromKey() untuk jawaban yes/no syarat spesifik.
     */
    private const REQUIREMENT_KEYWORDS = [
        'usia'               => ['usia', 'umur', 'age'],
        'ipk'                => ['ipk', 'gpa', 'indeks prestasi'],
        'tes_bahasa_inggris' => ['toefl', 'ielts', 'duolingo', 'bahasa inggris', 'english test', 'english proficiency', 'tes bahasa inggris'],
        'kewarganegaraan'    => ['kewarganegaraan', 'warga negara', 'nationality', 'citizenship', 'paspor', 'passport'],
        'bahasa_lain'        => ['bahasa lain', 'bahasa asing', 'other language', 'jlpt', 'tef', 'dele', 'hsk', 'topik', 'goethe', 'delf'],
        'tes_standar'        => ['gre', 'gmat', 'sat', 'act', 'standardized test', 'tes standar'],
        'dokumen'            => ['dokumen', 'berkas', 'documents', 'transkrip', 'transcript', 'ijazah', 'cv', 'curriculum vitae', 'paspor', 'passport'],
        'khusus'             => ['surat rekomendasi', 'rekomendasi', 'recommendation', 'motivation letter', 'motivasi', 'essay', 'esai', 'loa', 'letter of acceptance', 'wawancara', 'interview', 'pengalaman'],
    ];

    /**
     * Sinonim NILAI SPESIFIK per kategori (kanonik => daftar pola yang dicari di teks
     * persyaratan). Dipakai saat user menyebut nilai tertentu (mis. "berbahasa arab",
     * "tes GRE", "dokumen transkrip"). Untuk bahasa lain, nama TES dipakai sebagai
     * petunjuk bahasa (HSK->Mandarin, JLPT->Jepang, DSH->Jerman, DELF->Prancis, TOPIK->Korea).
     */
    private const REQUIREMENT_VALUE_SYNONYMS = [
        'bahasa_lain' => [
            'arab'    => ['bahasa arab', 'arabic'],
            'mandarin'=> ['mandarin', 'chinese', 'tiongkok', 'hsk', 'bahasa mandarin'],
            'jepang'  => ['bahasa jepang', 'japanese', 'nihongo', 'jlpt'],
            'jerman'  => ['bahasa jerman', 'german', 'deutsch', 'dsh', 'testdaf', 'goethe'],
            'prancis' => ['bahasa prancis', 'bahasa perancis', 'french', 'francais', 'delf', 'dalf', 'tef'],
            'korea'   => ['bahasa korea', 'korean', 'topik', 'hangul'],
            'spanyol' => ['bahasa spanyol', 'spanish', 'espanol', 'dele', 'siele'],
            'italia'  => ['bahasa italia', 'italian', 'italiano'],
            'rusia'   => ['bahasa rusia', 'russian', 'torfl'],
            'belanda' => ['bahasa belanda', 'dutch', 'nt2'],
        ],
        'kewarganegaraan' => [
            'indonesia' => ['indonesia', 'indonesian', 'wni'],
        ],
        'tes_standar' => [
            'gre'     => ['gre'],
            'gmat'    => ['gmat'],
            'sat'     => ['sat'],
            'act'     => ['act'],
            'a-level' => ['a-level', 'a level', 'alevel', 'gce'],
            'ib'      => ['ib', 'international baccalaureate'],
            'gat'     => ['gat', 'csca'],
        ],
        'tes_bahasa_inggris' => [
            'ielts'    => ['ielts'],
            'toefl'    => ['toefl'],
            'duolingo' => ['duolingo'],
            'toeic'    => ['toeic'],
            'pte'      => ['pte'],
        ],
        'dokumen' => [
            'transkrip'        => ['transkrip', 'transcript'],
            'paspor'           => ['paspor', 'passport'],
            'rekomendasi'      => ['surat rekomendasi', 'rekomendasi', 'recommendation', 'reference letter', 'lor'],
            'motivation letter'=> ['motivation letter', 'motivation essay', 'surat motivasi', 'motivation'],
            'esai'             => ['esai', 'essay', 'personal statement'],
            'cv'               => ['cv', 'curriculum vitae', 'resume'],
            'ijazah'           => ['ijazah', 'diploma', 'degree certificate', 'graduation certificate'],
            'keuangan'         => ['financial', 'keuangan', 'bank statement', 'financial statement'],
            'ktp'              => ['ktp', 'national id', 'id card'],
        ],
    ];

    /**
     * Ambil teks section persyaratan untuk SATU kategori. Untuk baris terstruktur
     * (header KAPITAL) -> nilai section terkait (bisa '' bila kategori tak disebut).
     * Untuk baris non-terstruktur -> gabungan teks persyaratan+deskripsi+benefit.
     */
    private function getRequirementSectionText($scholarship, string $jsonKey): string
    {
        $s = (array) $scholarship;
        $persyaratan = (string) ($s['persyaratan'] ?? '');

        $sections = $this->parsePersyaratanSections($persyaratan);
        if ($sections !== null) {
            $header = self::REQUIREMENT_SECTION_MAP[$jsonKey] ?? null;
            return $header !== null ? trim((string) ($sections[$header] ?? '')) : '';
        }
        return trim($persyaratan);
    }

    /**
     * Tentukan status SATU kategori persyaratan pada sebuah baris beasiswa.
     * Mengembalikan 'required' (kategori disyaratkan) atau 'absent' (tidak
     * disyaratkan / kosong / dinegasikan). Deterministik, TANPA panggilan LLM.
     */
    private function requirementSectionState($scholarship, string $jsonKey): string
    {
        $sections = $this->parsePersyaratanSections((string) (((array) $scholarship)['persyaratan'] ?? ''));

        // 1) Format TERSTRUKTUR: cek nilai section terkait.
        if ($sections !== null) {
            $val = $this->getRequirementSectionText($scholarship, $jsonKey);
            if ($val !== '' && !$this->isNegatedRequirement(strtolower($val))) {
                return 'required';
            }
            return 'absent';
        }

        // 2) Non-terstruktur: analisis berbasis keyword di gabungan teks.
        $combined = $this->getRequirementSectionText($scholarship, $jsonKey);
        $keywords = self::REQUIREMENT_KEYWORDS[$jsonKey] ?? [];
        $analysis = $this->analyzeRequirement($combined, $keywords);
        return $analysis['status'] === 'required' ? 'required' : 'absent';
    }

    /**
     * Apakah baris beasiswa cocok dengan NILAI SPESIFIK yang diminta user untuk
     * sebuah kategori (mis. bahasa_lain="arab", ipk="3.0", tes_standar="gre").
     * Deterministik & best-effort. Kategori angka (ipk/usia) -> eligibilitas;
     * kategori nama -> pencocokan sinonim pada teks section.
     */
    private function requirementValueMatches($scholarship, string $key, string $val): bool
    {
        $val = trim(strtolower($val));
        if ($val === '') return true;

        // --- Kategori ANGKA: pakai teks TERFOKUS pada kategori (bukan seluruh teks),
        // agar angka tak salah diambil dari bagian lain (mis. "IELTS 7", tahun, biaya). ---
        if ($key === 'ipk' || $key === 'usia' || ($key === 'tes_bahasa_inggris' && preg_match('/\d/', $val))) {
            $numText = strtolower($this->getNumericSectionText($scholarship, $key));
            $numNeg = $numText === '' || $this->isNegatedRequirement($numText);
            if ($key === 'ipk')  return $this->gpaEligible($numText, $val, $numNeg);
            if ($key === 'usia') return $this->ageEligible($numText, $val, $numNeg);
            return $this->englishScoreEligible($numText, $val, $numNeg);
        }

        // --- Kategori NAMA: requirement match (sinonim muncul di teks persyaratan). ---
        $sectionText = strtolower($this->getRequirementSectionText($scholarship, $key));
        $negatedOrEmpty = $sectionText === '' || $this->isNegatedRequirement($sectionText);
        // Section kosong/dinegasikan => kategori ini tidak disyaratkan => tak cocok.
        if ($negatedOrEmpty) return false;

        // Khusus bahasa_lain: jika user mencari "sertifikat" atau "tes" bahasa tertentu,
        // pastikan beasiswa tersebut memang mensyaratkan sertifikasi/tes (seperti JLPT, HSK, TOPIK,
        // sertifikat bahasa, dll) dan bukan sekadar menyebut nama negaranya/bahasanya untuk hal lain (mis. sertifikat pajak).
        if ($key === 'bahasa_lain' && preg_match('/\b(sertifikat|sertifikasi|certificate|tes|test|bukti|skor|score|jlpt|hsk|topik|dele|goethe|testdaf|dsh|delf|dalf|tef|torfl)\b/i', $val)) {
            $certPatterns = [
                'jepang' => ['jlpt', 'nihongo noryoku shiken', 'nihongo', 'n1', 'n2', 'n3', 'n4', 'n5', 'sertifikat bahasa jepang', 'sertifikat kemampuan bahasa jepang', 'tes bahasa jepang', 'kemampuan bahasa jepang', 'kemahiran bahasa jepang'],
                'mandarin' => ['hsk', 'tocfl', 'sertifikat bahasa mandarin', 'sertifikat mandarin', 'sertifikat bahasa tiongkok', 'kemampuan bahasa mandarin', 'kemampuan bahasa china'],
                'korea' => ['topik', 'sertifikat bahasa korea', 'sertifikat topik', 'kemampuan bahasa korea'],
                'jerman' => ['goethe', 'testdaf', 'dsh', 'sertifikat bahasa jerman', 'kemampuan bahasa jerman'],
                'prancis' => ['delf', 'dalf', 'tef', 'sertifikat bahasa prancis', 'kemampuan bahasa prancis'],
                'spanyol' => ['dele', 'siele', 'sertifikat bahasa spanyol', 'kemampuan bahasa spanyol'],
                'arab' => ['toafl', 'sertifikat bahasa arab', 'kemampuan bahasa arab'],
                'inggris' => ['toefl', 'ielts', 'duolingo', 'toeic', 'pte']
            ];

            $detectedLang = null;
            foreach (['jepang', 'mandarin', 'korea', 'jerman', 'prancis', 'spanyol', 'arab', 'inggris'] as $lang) {
                if (str_contains($val, $lang)) {
                    $detectedLang = $lang;
                    break;
                }
            }
            if (!$detectedLang) {
                foreach (self::REQUIREMENT_VALUE_SYNONYMS['bahasa_lain'] as $lang => $langPats) {
                    foreach ($langPats as $p) {
                        if ($p !== '' && str_contains($val, $p)) {
                            $detectedLang = $lang;
                            break 2;
                        }
                    }
                }
            }

            if ($detectedLang && isset($certPatterns[$detectedLang])) {
                $matchedCert = false;
                foreach ($certPatterns[$detectedLang] as $cp) {
                    if (preg_match('/\b' . preg_quote($cp, '/') . '\b/ui', $sectionText)) {
                        $matchedCert = true;
                        break;
                    }
                }
                if (!$matchedCert) {
                    return false;
                }
            }
        }

        foreach ($this->resolveValuePatterns($key, $val) as $pat) {
            // Pakai BATAS KATA (\b) bukan substring, agar akronim pendek (ib/gre/sat/act)
            // tidak salah cocok di tengah kata lain ("wajib", "degree", "satu").
            if ($pat !== '' && preg_match('/\b' . preg_quote($pat, '/') . '\b/u', $sectionText)) return true;
        }
        return false;
    }

    /**
     * Teks persyaratan TERFOKUS untuk kategori bernilai ANGKA. Baris terstruktur ->
     * nilai section terkait. Baris non-terstruktur -> POTONGAN di sekitar kata kunci
     * kategori (mis. "ipk"/"gpa"), dan '' bila kategori TIDAK disebut sama sekali —
     * sehingga beasiswa yang tak menyebut syarat itu tidak salah lolos.
     */
    private function getNumericSectionText($scholarship, string $jsonKey): string
    {
        $s = (array) $scholarship;
        $persyaratan = (string) ($s['persyaratan'] ?? '');

        $sections = $this->parsePersyaratanSections($persyaratan);
        if ($sections !== null) {
            $header = self::REQUIREMENT_SECTION_MAP[$jsonKey] ?? null;
            return $header !== null ? trim((string) ($sections[$header] ?? '')) : '';
        }

        $analysis = $this->analyzeRequirement($persyaratan, self::REQUIREMENT_KEYWORDS[$jsonKey] ?? []);
        return $analysis['snippet']; // '' bila kata kunci kategori tak ditemukan.
    }

    /**
     * Daftar pola yang dicari untuk sebuah nilai user pada kategori nama.
     * Cocokkan nilai user ke kanonik di REQUIREMENT_VALUE_SYNONYMS; jika tak
     * dikenal, pakai kata-kata (>=3 huruf) dari nilai user itu sendiri.
     */
    private function resolveValuePatterns(string $key, string $val): array
    {
        $map = self::REQUIREMENT_VALUE_SYNONYMS[$key] ?? [];
        foreach ($map as $canonical => $patterns) {
            if (str_contains($val, $canonical)) return $patterns;
            foreach ($patterns as $p) {
                if ($p !== '' && str_contains($val, $p)) return $patterns;
            }
        }
        // Fallback: token mentah dari nilai user (mis. bahasa/dokumen tak terdaftar).
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $val),
            fn($t) => strlen($t) >= 3
        ));
        return !empty($tokens) ? $tokens : [$val];
    }

    /** Normalisasi nilai IPK ke pecahan 0..1 (3.0->0.75 skala 4; 85->0.85 skala 100). */
    private function gpaToFraction(float $num): float
    {
        if ($num <= 0) return 0.0;
        if ($num > 5) return min($num / 100, 1.0);   // skala 100 (rapor/persen)
        return min($num / 4.0, 1.0);                  // skala 4.0 (GPA)
    }

    /**
     * Eligibilitas IPK: true bila beasiswa MEMILIKI syarat IPK dan ambangnya <= IPK user.
     * Beasiswa TANPA syarat IPK SENGAJA TIDAK dimunculkan saat user memfilter nilai IPK,
     * karena user secara eksplisit mencari beasiswa yang punya syarat IPK tertentu.
     */
    private function gpaEligible(string $sectionText, string $val, bool $negatedOrEmpty): bool
    {
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $val, $um)) return true;
        $userFrac = $this->gpaToFraction((float) $um[1]);

        if ($negatedOrEmpty) return false; // tak ada syarat IPK -> tidak ditampilkan.

        // Ambil "X out of Y" bila ada; jika tidak, angka pertama dengan skala tebakan.
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:out of|\/)\s*([0-9]+(?:\.[0-9]+)?)/i', $sectionText, $m)) {
            $rowFrac = ((float) $m[2]) > 0 ? ((float) $m[1]) / ((float) $m[2]) : 0.0;
        } elseif (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $sectionText, $m)) {
            $rowFrac = $this->gpaToFraction((float) $m[1]);
        } else {
            return true; // ada teks tapi tak ada angka -> best-effort: jangan buang.
        }
        return $rowFrac <= $userFrac + 1e-6;
    }

    /**
     * Eligibilitas usia: true bila beasiswa MEMILIKI syarat usia dan usia user
     * berada di dalam [Min,Max]. Beasiswa TANPA syarat usia TIDAK dimunculkan saat
     * user memfilter usia (konsisten dengan filter IPK).
     */
    private function ageEligible(string $sectionText, string $val, bool $negatedOrEmpty): bool
    {
        if (!preg_match('/([0-9]{1,2})/', $val, $um)) return true;
        $userAge = (int) $um[1];
        if ($negatedOrEmpty) return false; // tak ada syarat usia -> tidak ditampilkan.

        $max = preg_match('/max\s*age[:\s]*([0-9]{1,2})/i', $sectionText, $mm) ? (int) $mm[1] : null;
        $min = preg_match('/min\s*age[:\s]*([0-9]{1,2})/i', $sectionText, $mn) ? (int) $mn[1] : null;
        if ($max === null && $min === null) return true; // tak terdeteksi -> best-effort.
        if ($max !== null && $userAge > $max) return false;
        if ($min !== null && $userAge < $min) return false;
        return true;
    }

    /**
     * Eligibilitas skor tes bahasa Inggris (mis. "toefl 80"): true bila beasiswa
     * MEMILIKI syarat tes dan skor user >= ambang. Beasiswa TANPA syarat tes bahasa
     * Inggris TIDAK dimunculkan saat user memfilter skor (konsisten dengan filter IPK).
     */
    private function englishScoreEligible(string $sectionText, string $val, bool $negatedOrEmpty): bool
    {
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $val, $um)) return true;
        $userScore = (float) $um[1];
        if ($negatedOrEmpty) return false; // tes tak diwajibkan -> tidak ditampilkan.

        // Tentukan tes yang dimaksud (default: cari skor min mana pun di section).
        $test = '';
        foreach (['ielts', 'toefl', 'duolingo', 'toeic', 'pte'] as $t) {
            if (str_contains($val, $t)) { $test = $t; break; }
        }
        $pattern = $test !== ''
            ? '/' . preg_quote($test, '/') . '[^0-9]{0,20}([0-9]+(?:\.[0-9]+)?)/i'
            : '/min[^0-9]{0,8}([0-9]+(?:\.[0-9]+)?)/i';
        if (preg_match($pattern, $sectionText, $m)) {
            return $userScore + 1e-6 >= (float) $m[1];
        }
        return true; // tak bisa pastikan -> best-effort: jangan buang.
    }

    /**
     * Rangkai deskripsi ringkas filter persyaratan untuk header/pesan respons,
     * mis. ['bahasa_lain'=>'ada','tes_bahasa_inggris'=>'tanpa'] ->
     * "dengan syarat Bahasa Lain, tanpa Tes Bahasa Inggris".
     */
    private function describeReqFilters(array $reqFilters): string
    {
        $ada = [];
        $tanpa = [];
        foreach ($reqFilters as $key => $want) {
            $label = self::REQUIREMENT_LABELS[$key] ?? null;
            if ($label === null) continue;
            if ($want === 'ada') $ada[] = $label;
            elseif ($want === 'tanpa') $tanpa[] = $label;
        }
        $parts = [];
        if (!empty($ada)) $parts[] = 'dengan syarat ' . implode(', ', $ada);
        if (!empty($tanpa)) $parts[] = 'tanpa ' . implode(', ', $tanpa);
        return implode(', ', $parts);
    }

    /**
     * Deskripsi ringkas filter NILAI SPESIFIK untuk header/pesan respons,
     * mis. ['bahasa_lain'=>'arab','ipk'=>'3.0'] -> "Bahasa Lain: Arab, IPK: 3.0".
     */
    private function describeReqValues(array $reqValues): string
    {
        $parts = [];
        foreach ($reqValues as $key => $val) {
            $label = self::REQUIREMENT_LABELS[$key] ?? null;
            if ($label === null || trim((string) $val) === '') continue;
            $parts[] = $label . ': ' . ucwords((string) $val);
        }
        return implode(', ', $parts);
    }

    /**
     * Key kategori syarat pertama yang disebut user (dari req_filters/req_values).
     * Dipakai untuk merutekan pertanyaan validasi yes/no ke handleSpecificRequirementCheck.
     */
    private function firstRequirementKey($criteria): ?string
    {
        foreach (['req_filters', 'req_values'] as $bag) {
            if (!empty($criteria[$bag]) && is_array($criteria[$bag])) {
                $keys = array_keys($criteria[$bag]);
                if (!empty($keys)) return (string) $keys[0];
            }
        }
        return null;
    }

    /**
     * Bangun argumen ['label','keywords'] untuk handleSpecificRequirementCheck dari key
     * kategori, memanfaatkan konstanta yang sudah ada (pengganti getSpecificRequirementQuery).
     */
    private function reqQueryFromKey(string $key, $criteria = null): ?array
    {
        $label = self::REQUIREMENT_LABELS[$key] ?? null;
        if ($label === null) return null;

        $keywords = self::REQUIREMENT_KEYWORDS[$key] ?? [];

        if ($criteria && !empty($criteria['req_values'][$key])) {
            $val = strtolower(trim($criteria['req_values'][$key]));
            $synonyms = self::REQUIREMENT_VALUE_SYNONYMS[$key] ?? [];
            foreach ($synonyms as $canonical => $pats) {
                if (str_contains($val, $canonical)) {
                    $keywords = $pats;
                    $label = ucwords($canonical);
                    break;
                }
                foreach ($pats as $p) {
                    if ($p !== '' && str_contains($val, $p)) {
                        $keywords = $pats;
                        $label = ucwords($canonical);
                        break 2;
                    }
                }
            }
        }

        return ['label' => $label, 'keywords' => $keywords];
    }

    /** Gabungan deskripsi req_filters (ada/tanpa) + req_values (nilai spesifik). */
    private function describeRequirements($criteria): string
    {
        $bits = [];
        if (!empty($criteria['req_filters'])) {
            $d = $this->describeReqFilters($criteria['req_filters']);
            if ($d !== '') $bits[] = $d;
        }
        if (!empty($criteria['req_values'])) {
            $d = $this->describeReqValues($criteria['req_values']);
            if ($d !== '') $bits[] = $d;
        }
        return implode(', ', $bits);
    }

    /**
     * Parse teks persyaratan TERSTRUKTUR (ber-header KAPITAL) menjadi array berkunci
     * kanonik (AGE, GPA, ...). Mengembalikan null jika teks BUKAN format terstruktur
     * (mis. kalimat Indonesia biasa) sehingga caller bisa pakai jalur ekstraksi LLM.
     *
     * Ekstraksi dilakukan BERURUTAN sesuai urutan kanonik header — bukan split per
     * kemunculan kata — karena "GPA"/"DOCUMENTS" juga muncul di dalam nilai section lain.
     */
    private function parsePersyaratanSections($text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') return null;

        $headers = array_keys(self::PERSYARATAN_SECTIONS);

        // Deteksi terstruktur: butuh penanda khas Inggris yang TAK muncul di kalimat
        // Indonesia (NATIONALITY / STANDARDIZED TEST / OTHER LANGUAGE) + minimal 3 header.
        $hasMarker = preg_match('/\b(NATIONALITY|STANDARDIZED TEST|OTHER LANGUAGE)\b/', $text);
        $headerHits = 0;
        foreach ($headers as $h) {
            if (preg_match('/\b' . preg_quote($h, '/') . '\b/', $text)) $headerHits++;
        }
        if (!$hasMarker || $headerHits < 3) return null;

        // Cari posisi kemunculan tiap header SECARA BERURUTAN, mulai dari posisi setelah
        // header sebelumnya. Ini mencegah salah-tangkap "GPA" yang ada di dalam nilai.
        $positions = [];
        $cursor = 0;
        foreach ($headers as $h) {
            if (preg_match('/\b' . preg_quote($h, '/') . '\b/', $text, $m, PREG_OFFSET_CAPTURE, $cursor)) {
                $positions[$h] = ['start' => $m[0][1], 'len' => strlen($m[0][0])];
                $cursor = $m[0][1] + strlen($m[0][0]);
            }
        }
        if (empty($positions)) return null;

        // Nilai tiap header = teks antara akhir header ini s/d awal header berikut yang ketemu.
        $found = array_keys($positions);
        $sections = [];
        foreach ($found as $i => $h) {
            $valStart = $positions[$h]['start'] + $positions[$h]['len'];
            $valEnd = isset($found[$i + 1]) ? $positions[$found[$i + 1]]['start'] : strlen($text);
            $sections[$h] = trim(substr($text, $valStart, $valEnd - $valStart), " .\t\n\r");
        }
        // Pastikan semua key kanonik ada (yang tak ketemu = kosong → "tanpa syarat").
        foreach ($headers as $h) {
            if (!isset($sections[$h])) $sections[$h] = '';
        }
        return $sections;
    }

    /**
     * Terjemahkan nilai section (Inggris) ke Indonesia lewat SATU panggilan LLM.
     * Struktur tetap dirakit di PHP; hanya nilai yang diterjemahkan. Jika gagal,
     * kembalikan section apa adanya (fallback aman, format tetap konsisten).
     */
    private function translateSectionValues(array $sections): array
    {
        $toTranslate = [];
        foreach ($sections as $key => $val) {
            if ($val !== '' && !$this->isNegatedRequirement(strtolower($val))) {
                $toTranslate[$key] = $val;
            }
        }
        if (empty($toTranslate)) return $sections;

        try {
            $system = "Anda penerjemah info beasiswa. Terjemahkan SETIAP nilai pada objek JSON berikut "
                . "ke Bahasa Indonesia yang ringkas & formal. PERTAHANKAN key apa adanya. Jangan tambah "
                . "atau hapus key. Jika satu nilai berisi beberapa poin, pisahkan tiap poin dengan baris baru (\\n). "
                . "Pertahankan istilah baku (TOEFL, IELTS, GPA, CV, GMAT, dll). Balas HANYA objek JSON.";
            $raw = $this->callChatLLM($system, json_encode($toTranslate, JSON_UNESCAPED_UNICODE), true, 0.2);
            $raw = trim(preg_replace('/```(?:json)?/i', '', (string) $raw));
            $raw = trim(str_replace('```', '', $raw));
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $val) {
                    if (isset($sections[$key]) && is_string($val) && trim($val) !== '') {
                        $sections[$key] = trim($val);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("translateSectionValues error: " . $e->getMessage());
        }
        return $sections;
    }

    /**
     * Ekstrak teks persyaratan NON-terstruktur (kalimat Indonesia) ke 8 section kanonik
     * via LLM. Mengembalikan array berkunci kanonik, atau null jika LLM gagal (caller fallback).
     */
    private function extractSectionsFromText($text): ?array
    {
        $keyMap = self::REQUIREMENT_SECTION_MAP;
        try {
            $system = "Anda asisten beasiswa. Petakan teks persyaratan beasiswa ke 8 kategori tetap. "
                . "Balas HANYA objek JSON dengan key persis: usia, ipk, tes_bahasa_inggris, kewarganegaraan, "
                . "bahasa_lain, tes_standar, dokumen, khusus. Nilai = ringkasan Bahasa Indonesia untuk kategori itu. "
                . "Jika kategori TIDAK disebut di teks, isi string kosong \"\". Apa pun yang tidak masuk 7 kategori "
                . "pertama, masukkan ke \"khusus\". Jangan mengarang; hanya berdasarkan teks. Jika satu nilai berisi "
                . "beberapa poin, pisahkan dengan baris baru (\\n).";
            $raw = $this->callChatLLM($system, $text, true, 0.2);
            $raw = trim(preg_replace('/```(?:json)?/i', '', (string) $raw));
            $raw = trim(str_replace('```', '', $raw));
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) return null;

            $sections = [];
            foreach ($keyMap as $jsonKey => $canonical) {
                $sections[$canonical] = is_string($decoded[$jsonKey] ?? null) ? trim($decoded[$jsonKey]) : '';
            }
            return $sections;
        } catch (\Exception $e) {
            Log::error("extractSectionsFromText error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Rakit template 8-section yang konsisten dari array section kanonik.
     * Section kosong / dinegasikan → kalimat "tanpa syarat". Section berisi → bullet markdown.
     */
    private function formatPersyaratanTemplate(array $sections): string
    {
        $lines = [];
        foreach (self::PERSYARATAN_SECTIONS as $key => $label) {
            $val = trim((string) ($sections[$key] ?? ''));
            $lines[] = "**{$label}**";

            if ($val === '' || $this->isNegatedRequirement(strtolower($val))) {
                $lines[] = self::PERSYARATAN_EMPTY_MSG[$key];
                $lines[] = '';
                continue;
            }

            // Perapian ringan + pecah jadi poin-poin.
            $val = preg_replace('/\bmin\.\s*/i', 'minimal ', $val);
            $points = preg_split('/\r\n|\r|\n|•|;/u', $val);
            $points = array_values(array_filter(array_map(fn($p) => trim($p, " .\t"), $points), fn($p) => $p !== ''));
            if (empty($points)) $points = [$val];
            foreach ($points as $p) {
                $lines[] = "- " . $p;
            }
            $lines[] = '';
        }
        return rtrim(implode("\n", $lines));
    }

    /**
     * Format SATU baris beasiswa untuk daftar, dengan format LENGKAP & konsisten:
     * "N. **Nama** (Luar Negeri (Negara) - Jenjang - Pendanaan) - Deadline: ..."
     * Dipakai di semua tampilan daftar (search, paginasi, kembali ke list).
     */
    private function formatScholarshipLine($s, $number, $englishFunding = false, $jurusanNote = '')
    {
        $s = (array) $s;
        $nama = trim($s['nama_beasiswa'] ?? 'Beasiswa');

        $attrs = [];

        // Lokasi: tipe (Dalam/Luar Negeri) + negara.
        // Beberapa baris sudah memuat tipe di kolom negara (mis. "Luar Negeri (China)") -
        // pakai apa adanya agar tidak terjadi duplikasi "Luar Negeri (Luar Negeri (China))".
        $negara = trim($s['negara'] ?? '');
        $negaraLower = strtolower($negara);
        if ($negara === '') {
            $attrs[] = 'Luar Negeri';
        } elseif (str_contains($negaraLower, 'luar negeri') || str_contains($negaraLower, 'dalam negeri')) {
            $attrs[] = ucwords($negara);
        } else {
            $lokasiTipe = str_contains($negaraLower, 'indonesia') ? 'Dalam Negeri' : 'Luar Negeri';
            $attrs[] = $lokasiTipe . ' (' . ucwords($negara) . ')';
        }

        // Jenjang
        $attrs[] = !empty($s['jenjang']) ? $s['jenjang'] : '-';

        // Pendanaan
        $attrs[] = $this->getKategoriDisplay($s['kategori'] ?? '', $englishFunding);

        return $number . ". **{$nama}** - " . implode(' - ', $attrs) . " - Deadline: " . ($s['deadline'] ?? '-') . $jurusanNote;
    }

    /**
     * Apakah baris beasiswa menyebut SECARA EKSPLISIT salah satu jurusan yang dicari user?
     * "Semua Jurusan / all major" (atau kosong) dianggap GENERIK (terbuka untuk semua jurusan),
     * bukan match eksplisit — dipakai untuk pemeringkatan & pelabelan (Opsi 1).
     */
    private function bidangMatchesExplicitly($s, array $bidangList): bool
    {
        $s = (array) $s;
        $rowBidang = strtolower($s['jurusan'] ?? '');
        $isGeneric = $rowBidang === '' || $rowBidang === '-'
            || str_contains($rowBidang, 'semua') || str_contains($rowBidang, 'all') || str_contains($rowBidang, 'any');
        if ($isGeneric) return false;

        $hay = $rowBidang . ' ' . strtolower($s['deskripsi'] ?? '') . ' ' . strtolower($s['persyaratan'] ?? '');
        foreach ($bidangList as $b) {
            if ($b !== '' && str_contains($hay, $b)) return true;
        }
        return false;
    }

    /**
     * Label transparan untuk daftar saat user mencari jurusan tertentu:
     * beasiswa yang cocok HANYA karena terbuka untuk semua jurusan diberi penanda,
     * sedangkan yang menyebut jurusan secara eksplisit tidak diberi penanda.
     */
    private function jurusanNote($s, array $bidangList): string
    {
        if (empty($bidangList)) return '';
        return $this->bidangMatchesExplicitly($s, $bidangList) ? '' : ' _(terbuka untuk semua jurusan)_';
    }

    /**
     * Konversi nilai kolom `kategori` menjadi label pendanaan yang ramah pengguna.
     */
    private function getKategoriDisplay($kategori, $english = false)
    {
        $kat = strtolower($kategori ?? '');
        $hasFull = str_contains($kat, 'fully') || str_contains($kat, 'penuh');
        $hasPartial = str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial');

        if ($english) {
            if ($hasFull && $hasPartial) return 'Fully & Partially Funded';
            if ($hasFull) return 'Fully Funded';
            if ($hasPartial) return 'Partially Funded';
            return !empty($kategori) ? ucwords($kategori) : 'Partially Funded';
        }

        if ($hasFull && $hasPartial) return 'Pendanaan Penuh & Sebagian';
        if ($hasFull) return 'Pendanaan Penuh (Full Gratis)';
        if ($hasPartial) return 'Pendanaan Sebagian (Parsial)';
        return !empty($kategori) ? ucwords($kategori) : 'Pendanaan Sebagian (Parsial)';
    }

    private function showLastList()
    {
        $results = session()->get('last_search_results', []);
        if (empty($results)) {
            return $this->finalizeResponse("Maaf, belum ada daftar beasiswa sebelumnya. Silakan lakukan pencarian terlebih dahulu ya. 😊");
        }
        $page = session()->get('last_search_page', 1);
        $startIndex = ($page - 1) * 5;
        session()->forget('selected_scholarship');

        $bidangList = session()->get('last_search_criteria')['bidang'] ?? [];
        $resp = "Berikut kembali daftar beasiswa sebelumnya:\n\n";
        foreach ($results as $i => $s) {
            $num = $startIndex + $i + 1;
            $resp .= $this->formatScholarshipLine($s, $num, false, $this->jurusanNote($s, $bidangList)) . "\n\n";
        }
        $resp .= "Silakan ketik nomor beasiswa untuk melihat detailnya kembali.";
        return $this->finalizeResponse($resp);
    }

    private function handleNextPage($normalizedData)
    {
        $allResults = session()->get('last_search_all_results', []);
        $page = session()->get('last_search_page', 1);

        $nextPage = $page + 1;
        $startIndex = ($nextPage - 1) * 5;
        
        if ($startIndex >= count($allResults)) {
            return $this->finalizeResponse("Maaf, sudah tidak ada lagi daftar beasiswa lainnya untuk pencarian tersebut.", $normalizedData);
        }

        $limitedResults = array_slice($allResults, $startIndex, 5);
        session()->put('last_search_page', $nextPage);
        session()->put('last_search_results', $limitedResults);
        session()->forget('selected_scholarship');

        $bidangList = session()->get('last_search_criteria')['bidang'] ?? [];
        $resp = "Berikut daftar beasiswa selanjutnya:\n\n";
        foreach ($limitedResults as $i => $s) {
            $displayNumber = $startIndex + $i + 1;
            $resp .= $this->formatScholarshipLine($s, $displayNumber, false, $this->jurusanNote($s, $bidangList)) . "\n\n";
        }

        if (count($allResults) > $startIndex + 5) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau silakan pilih nomor beasiswa untuk melihat **detail**.";
        } else {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function validateCriteriaAvailability($criteria)
    {
        foreach ($criteria['negara'] ?? [] as $neg) {
            if ($neg === 'indonesia') continue;
            if (!DB::table('scholarships')->where('negara', 'ilike', '%' . $neg . '%')->exists()) {
                return "Mohon maaf, data beasiswa untuk negara **" . ucwords($neg) . "** belum tersedia di database kami saat ini. 😊";
            }
        }

        foreach ($criteria['benua'] ?? [] as $ben) {
            $terms = [$ben];
            if ($ben === 'amerika') $terms[] = 'america';
            if ($ben === 'eropa') $terms[] = 'europe';
            if ($ben === 'australia') $terms[] = 'oceania';
            $exists = DB::table('scholarships')->where(function ($q) use ($terms) {
                foreach ($terms as $t) $q->orWhere('benua', 'ilike', '%' . $t . '%');
            })->exists();
            if (!$exists) {
                return "Mohon maaf, data beasiswa untuk benua **" . ucwords($ben) . "** belum tersedia di database kami saat ini. 😊";
            }
        }

        foreach ($criteria['jenjang'] ?? [] as $jen) {
            if (!DB::table('scholarships')->where('jenjang', 'ilike', '%' . $jen . '%')->exists()) {
                return "Mohon maaf, data beasiswa jenjang **" . strtoupper($jen) . "** belum tersedia di database kami saat ini. 😊";
            }
        }

        if (!empty($criteria['funding'])) {
            $f = strtolower($criteria['funding']);
            if ($f === 'exchange') $terms = ['exchange', 'pertukaran'];
            elseif ($f === 'partially funded') $terms = ['partially', 'sebagian', 'partial'];
            else $terms = ['fully', 'penuh'];
            $exists = DB::table('scholarships')->where(function ($q) use ($terms) {
                foreach ($terms as $t) $q->orWhere('kategori', 'ilike', '%' . $t . '%');
            })->exists();
            if (!$exists) {
                return "Mohon maaf, data beasiswa kategori **" . $criteria['funding'] . "** belum tersedia di database kami saat ini. 😊";
            }
        }

        return null;
    }

    private function handleSearch($criteria, $rawText, $normalizedData = null)
    {
        $message = strtolower($rawText);

        $availabilityFallback = $this->validateCriteriaAvailability($criteria);
        if ($availabilityFallback !== null) {
            $this->currentIntent = 'search';
            return $this->finalizeResponse($availabilityFallback);
        }

        // =================================================================
        // INTENT WAKTU (sort_deadline / still_open / deadline_before) sepenuhnya dari
        // LLM via mapLlmToCriteria — tidak ada lagi deteksi regex pada pesan user.
        // =================================================================
        $hasTimeRangeIntent = !empty($criteria['still_open']) || !empty($criteria['deadline_before']) || !empty($criteria['sort_deadline']);

        // Daftar acak per tahun: hanya bila user menyebut TAHUN (dari LLM) TANPA filter
        // spesifik lain & tanpa intent rentang waktu. "General list request" tak lagi
        // dideteksi via regex — cukup ketiadaan filter spesifik lain.
        if (!empty($criteria['tahun']) && !$hasTimeRangeIntent) {
            $hasSpecificFilters = !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['jenjang']) || !empty($criteria['bidang']) || !empty($criteria['lokasi_tipe']) || !empty($criteria['funding']) || !empty($criteria['req_filters']) || !empty($criteria['req_values']);

            if (!$hasSpecificFilters) {
                $year = $criteria['tahun'][0];
                $allowedYears = ['2024', '2025', '2026', '2027'];
                if (!in_array($year, $allowedYears)) {
                    return $this->finalizeResponse("Mohon maaf, **ScholarBot** hanya menyediakan data untuk tahun **2024**, **2025**, **2026**, dan **2027** saat ini, terima kasih 😊", $normalizedData);
                }

                $all = DB::table('scholarships')
                    ->where('deadline', 'like', "%{$year}%")
                    ->select(self::SCHOLARSHIP_COLUMNS)
                    ->get()
                    ->toArray();

                shuffle($all);
                $all = array_slice($all, 0, 105);
                $limitedResults = array_slice($all, 0, 5);

                session()->put('last_search_all_results', $all);
                session()->put('last_search_page', 1);
                session()->put('last_search_results', $limitedResults);
                session()->forget('selected_scholarship');

                $countResult = count($all);
                $resp = "Berikut beasiswa tahun {$year} (menampilkan 5 dari {$countResult} data secara acak):\n\n";
                foreach ($limitedResults as $i => $s) {
                    $resp .= $this->formatScholarshipLine($s, $i + 1) . "\n\n";
                }
                if ($countResult > 5) {
                    $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau ketik nomor beasiswa untuk melihat **detail**.";
                } else {
                    $resp .= "Silakan ketik nomor beasiswa untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
                }

                $this->currentIntent = 'search';
                return $this->finalizeResponse($resp, $normalizedData);
            }
        }

        if (!empty($criteria['tahun'])) {
            $allowedYears = ['2024', '2025', '2026', '2027'];
            $hasInvalidYear = false;
            foreach ($criteria['tahun'] as $yr) {
                if (!in_array($yr, $allowedYears)) {
                    $hasInvalidYear = true;
                }
            }
            if ($hasInvalidYear) {
                return $this->finalizeResponse("Mohon maaf, **ScholarBot** hanya menyediakan data untuk tahun **2024**, **2025**, **2026**, dan **2027** saat ini, terima kasih 😊", $normalizedData);
            }
        }

        // CONTEXT MERGING & RESET — penentu sesi BARU vs LANJUTAN sepenuhnya dari LLM
        // (field query_scope). "follow_up" -> warisi kriteria lama yang tak diisi ulang;
        // "new_search"/null -> pencarian segar tanpa warisan agar kriteria lama tak bocor.
        if (($criteria['query_scope'] ?? null) === 'follow_up' && session()->has('last_search_criteria')) {
            $lastCriteria = session()->get('last_search_criteria');

            if (empty($criteria['negara']) && !empty($lastCriteria['negara'])) $criteria['negara'] = $lastCriteria['negara'];
            if (empty($criteria['benua']) && !empty($lastCriteria['benua'])) $criteria['benua'] = $lastCriteria['benua'];
            if (empty($criteria['lokasi_tipe']) && !empty($lastCriteria['lokasi_tipe'])) $criteria['lokasi_tipe'] = $lastCriteria['lokasi_tipe'];
            if (empty($criteria['jenjang']) && !empty($lastCriteria['jenjang'])) $criteria['jenjang'] = $lastCriteria['jenjang'];
            if (empty($criteria['bidang']) && !empty($lastCriteria['bidang'])) $criteria['bidang'] = $lastCriteria['bidang'];
            if (empty($criteria['funding']) && !empty($lastCriteria['funding'])) $criteria['funding'] = $lastCriteria['funding'];
            // Pertahankan filter persyaratan (req_filters & req_values) yang belum diisi ulang.
            if (empty($criteria['req_filters']) && !empty($lastCriteria['req_filters'])) $criteria['req_filters'] = $lastCriteria['req_filters'];
            if (empty($criteria['req_values']) && !empty($lastCriteria['req_values'])) $criteria['req_values'] = $lastCriteria['req_values'];
            // Pertahankan filter waktu agar re-run tidak memunculkan beasiswa kedaluwarsa / di luar rentang.
            if (empty($criteria['still_open']) && !empty($lastCriteria['still_open'])) $criteria['still_open'] = $lastCriteria['still_open'];
            if (empty($criteria['deadline_before']) && !empty($lastCriteria['deadline_before'])) $criteria['deadline_before'] = $lastCriteria['deadline_before'];
            if (empty($criteria['sort_deadline']) && !empty($lastCriteria['sort_deadline'])) {
                $criteria['sort_deadline'] = $lastCriteria['sort_deadline'];
                $criteria['sort_deadline_dir'] = $lastCriteria['sort_deadline_dir'] ?? 'asc';
            }
        }

        $stopWords = [
            'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke',
            'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong',
            'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'siapa', 'bagaimana', 'gimana'
        ];
        
        $searchQueryClean = $message;
        foreach ($stopWords as $sw) {
            $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
        }

        // --- FIX VECTOR PARADOX ---
        // Jika user minta tanpa tes bahasa, jangan biarkan Vector mencari kata "IELTS" 
        // karena malah akan menarik 1000 beasiswa yang MEWAJIBKAN IELTS.
        if (!empty($criteria['no_test'])) {
            $searchQueryClean = preg_replace('/\b(ielts|toefl|tanpa|test|tes|syarat|persyaratan|list)\b/i', '', $searchQueryClean);
        }
        if (!empty($criteria['no_interview'])) {
            $searchQueryClean = preg_replace('/\b(wawancara|interview|tanpa|proses)\b/i', '', $searchQueryClean);
        }
        // -----------------------------------

        $searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
        
        $cleanWords = explode(' ', $searchQueryClean);
        $cleanWords = array_filter($cleanWords, fn($w) => strlen($w) >= 3 && !preg_match('/^\d{4}$/', $w) && !in_array($w, ['tahun', 'thn', 'beasiswa', 'scholarship', 'kuliah', 'studi', 'luar', 'dalam', 'negeri', 'indonesia', 'nya', 'aja', 'lah', 'kok', 'sih', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'bagi', 'minta', 'info', 'data', 'list', 'kumpulan', 'tampilkan', 'carikan', 'nyari', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'masih', 'buka', 'tutup', 'aktif', 'terbuka', 'sekarang', 'saat', 'apa', 'aja', 'yg', 'yang', 'kalau', 'dn', 'ln', 'sarjana', 'magister', 'doktor', 'master', 'postgraduate', 'phd', 'doctor', 'doctoral', 'diploma']));
        if (!empty($cleanWords)) {
            $criteria['clean_subject_words'] = array_values($cleanWords);
        }

        if (strlen($searchQueryClean) > 3) {
            $searchQuery = $searchQueryClean;
        } else {
            $searchQuery = $message;
        }

        if (strlen($searchQuery) < 15 && !empty($criteria['negara'])) {
            $searchQuery = "beasiswa " . implode(' ', $criteria['negara']);
        }

        $vStart = microtime(true);
        $embedding = $this->generateEmbedding($searchQuery);
        $searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
            $searchQuery, '[' . implode(',', $embedding) . ']', 1000
        ]);
        $this->vectorSearchTime += (microtime(true) - $vStart);
        
        $ids = array_map(fn($r) => $r->id, $searchIds);
        
        if (empty($ids)) {
            $rawResults = [];
        } else {
            $rawResults = DB::table('scholarships')
                ->whereIn('id', $ids)
                ->select(self::SCHOLARSHIP_COLUMNS)
                ->get()
                ->all();
            
            $idMap = array_flip($ids);
            usort($rawResults, function($a, $b) use ($idMap, $criteria) {
                if (!empty($criteria['sort_deadline'])) {
                    $timeA = $this->parseDeadlineDate($a->deadline ?? '');
                    $timeB = $this->parseDeadlineDate($b->deadline ?? '');
                    $dir = $criteria['sort_deadline_dir'] ?? 'asc';
                    if ($dir === 'desc') {
                        $valA = $timeA ?? -1;
                        $valB = $timeB ?? -1;
                        return $valB - $valA;
                    } else {
                        $valA = $timeA ?? 9999999999;
                        $valB = $timeB ?? 9999999999;
                        return $valA - $valB;
                    }
                }
                return ($idMap[$a->id] ?? 999) - ($idMap[$b->id] ?? 999);
            });
        }

        $filteredRaw = $this->applyStrictFilters($rawResults, $criteria);

        $filtered = [];
        if (!empty($criteria['lokasi_tipe'])) {
            $isMintaDalam = ($criteria['lokasi_tipe'] === 'dalam');
            foreach ($filteredRaw as $r) {
                $neg = strtolower($r->negara ?? '');
                $isIndo = str_contains($neg, 'indonesia');
                
                if ($isMintaDalam && $isIndo) $filtered[] = $r;
                if (!$isMintaDalam && !$isIndo) $filtered[] = $r;
            }
        } else {
            $filtered = $filteredRaw;
        }

        $fallbackToOtherFunding = false;
        $originalFundingRequested = null;
        if (empty($filtered) && !empty($criteria['funding'])) {
            $originalFundingRequested = $criteria['funding'];
            $criteriaWithoutFunding = $criteria;
            unset($criteriaWithoutFunding['funding']);
            
            $filteredRawAlt = $this->applyStrictFilters($rawResults, $criteriaWithoutFunding);
            $filteredAlt = [];
            if (!empty($criteria['lokasi_tipe'])) {
                $isMintaDalam = ($criteria['lokasi_tipe'] === 'dalam');
                foreach ($filteredRawAlt as $r) {
                    $neg = strtolower($r->negara ?? '');
                    $isIndo = str_contains($neg, 'indonesia');
                    
                    if ($isMintaDalam && $isIndo) $filteredAlt[] = $r;
                    if (!$isMintaDalam && !$isIndo) $filteredAlt[] = $r;
                }
            } else {
                $filteredAlt = $filteredRawAlt;
            }
            
            if (!empty($filteredAlt)) {
                $filtered = $filteredAlt;
                $fallbackToOtherFunding = true;
            }
        }

        if (empty($criteria['negara']) && empty($criteria['sort_deadline'])) {
            shuffle($filtered);
        }

        // OPSI 1 - Pemeringkatan jurusan: dahulukan beasiswa yang menyebut jurusan
        // secara EKSPLISIT, baru kemudian yang "Semua Jurusan" (tetap ditampilkan).
        // Sort stabil (PHP 8+) sehingga urutan/acak di dalam tiap tier dipertahankan.
        if (!empty($criteria['bidang']) && empty($criteria['sort_deadline'])) {
            $bidangList = $criteria['bidang'];
            usort($filtered, function ($a, $b) use ($bidangList) {
                $ea = $this->bidangMatchesExplicitly($a, $bidangList) ? 0 : 1;
                $eb = $this->bidangMatchesExplicitly($b, $bidangList) ? 0 : 1;
                return $ea <=> $eb;
            });
        }

        if (empty($filtered)) {
            // Filter persyaratan aktif & tak ada yang cocok -> tolak tegas (tanpa melonggarkan).
            if (!empty($criteria['req_filters']) || !empty($criteria['req_values'])) {
                $reqDesc = $this->describeRequirements($criteria);
                return $this->finalizeResponse("Mohon maaf, tidak ditemukan beasiswa dengan kriteria persyaratan tersebut" . ($reqDesc !== '' ? " ($reqDesc)" : '') . ". 😊", $normalizedData);
            }
            if (!empty($criteria['negara']) || !empty($criteria['mentioned_location'])) {
                return $this->finalizeResponse("Mohon maaf, saya belum memiliki data beasiswa untuk negara/kategori tersebut. 😊", $normalizedData);
            }
            if (!empty($criteria['mentioned_target_group'])) {
                return $this->finalizeResponse("Mohon maaf, saya belum memiliki data beasiswa untuk target/kategori sasaran tersebut. 😊", $normalizedData);
            }
            return $this->finalizeResponse("Mohon maaf, saya tidak menemukan beasiswa yang sesuai dengan pencarian tersebut. 😊", $normalizedData);
        }

        $limitedResults = array_slice($filtered, 0, 5);
        
        session()->put('last_search_all_results', $filtered);
        session()->put('last_search_criteria', $criteria);
        session()->put('last_search_page', 1);
        session()->put('last_search_results', $limitedResults);
        session()->forget('selected_scholarship');
 
        $locContext = $this->getLocContext($criteria);
        $count = count($filtered);
        $isQuantification = $this->isQuantificationQuery($message);

        $isMonthSearch = !empty($criteria['bulan']);
        $isDeadlineSearch = !empty($criteria['sort_deadline']) || preg_match('/\b(deadline|dl|tanggal)\b/i', $message);

        if ($isQuantification) {
            $resp = "Total beasiswa yang ditemukan{$locContext} adalah **$count** beasiswa.\n\nBerikut rinciannya:\n\n";
        } else {
            $headerParts = [];
            if (!empty($criteria['bidang'])) $headerParts[] = "jurusan " . implode(', ', array_map('ucwords', $criteria['bidang']));
            if (!empty($criteria['jenjang'])) $headerParts[] = "jenjang " . implode('/', $criteria['jenjang']);
            if (!empty($criteria['negara'])) $headerParts[] = "di " . implode(', ', array_map('ucwords', $criteria['negara']));
            if (!empty($criteria['no_interview'])) $headerParts[] = "tanpa wawancara";
            if (!empty($criteria['no_test'])) $headerParts[] = "tanpa tes bahasa Inggris";
            if (!empty($criteria['ekonomi'])) $headerParts[] = "untuk ekonomi lemah";
            if (!empty($criteria['gender']) && $criteria['gender'] === 'perempuan') $headerParts[] = "khusus perempuan";
            if (!empty($criteria['fresh_grad'])) $headerParts[] = "untuk fresh graduate";
            if (!empty($criteria['benefit_keywords'])) {
                $headerParts[] = "dengan benefit " . implode(', ', $criteria['benefit_keywords']);
            }
            if (!empty($criteria['req_filters']) || !empty($criteria['req_values'])) {
                $reqDesc = $this->describeRequirements($criteria);
                if ($reqDesc !== '') $headerParts[] = $reqDesc;
            }
            $spec = implode(' ', $headerParts);

            if ($fallbackToOtherFunding) {
                $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $message);
                $requestedDisplay = ($originalFundingRequested === 'Fully Funded') 
                    ? ($isEnglishQuery ? 'Fully Funded' : 'Pendanaan Penuh (Full Gratis)') 
                    : ($isEnglishQuery ? 'Partially Funded' : 'Pendanaan Sebagian (Parsial)');
                
                if ($isEnglishQuery) {
                    $resp = "Mohon maaf, beasiswa $spec tidak tersedia untuk kategori **$requestedDisplay**. Berikut adalah beasiswa alternatif yang tersedia:\n\n";
                } else {
                    $resp = "Mohon maaf, beasiswa $spec tidak tersedia untuk kategori **$requestedDisplay**. Namun, berikut beasiswa alternatif yang tersedia:\n\n";
                }
            } else {
                $resp = "Berikut daftar beasiswa " . $spec . ":\n\n";
            }
        }

        $isEnglishQuery = (bool) preg_match('/\b(fully|partially|partial|fund)\b/i', $message);
        $bidangList = $criteria['bidang'] ?? [];
        $hasGenericJurusan = false;
        foreach ($limitedResults as $i => $s) {
            $note = $this->jurusanNote($s, $bidangList);
            if ($note !== '') $hasGenericJurusan = true;
            $resp .= $this->formatScholarshipLine($s, $i + 1, $isEnglishQuery, $note) . "\n\n";
        }

        // Penjelasan label "terbuka untuk semua jurusan" (Opsi 1) saat user mencari jurusan tertentu.
        if (!empty($bidangList) && $hasGenericJurusan) {
            $resp .= "ℹ️ Beasiswa bertanda _(terbuka untuk semua jurusan)_ menerima semua bidang studi. Namun, beasiswa ini tidak menyebut jurusan **" . implode(', ', array_map('ucwords', $bidangList)) . "** secara spesifik, jadi sebaiknya **cek langsung ke website resmi/universitas tujuan** untuk memastikan jurusan tersebut benar-benar dibuka di sana ya. 😊\n\n";
        }

        if (count($filtered) > 5) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau silakan pilih nomor beasiswa untuk melihat **detail**.";
        } elseif ($count > 0) {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function applyStrictFilters($results, $criteria)
    {
        $filtered = array_filter($results, function($r) use ($criteria) {
            $content = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? ''));
            
            // --- FIX REGEX IELTS ---
            if (!empty($criteria['no_test']) && (str_contains($content, 'toefl') || str_contains($content, 'ielts'))) {
                if (!preg_match('/(tanpa|tidak wajib|tidak memerlukan|tidak perlu|tidak butuh|tidak mensyaratkan|bebas).{0,20}(toefl|ielts)/i', $content)) {
                    return false;
                }
            }
            // -----------------------
            
            if (!empty($criteria['gender']) && $criteria['gender'] === 'perempuan') {
                if (!str_contains($content, 'perempuan') && !str_contains($content, 'wanita') && !str_contains($content, 'mahasiswi') && !str_contains($content, 'putri') && !str_contains($content, 'siswi')) {
                    return false;
                }
                // Jika beasiswa terbuka untuk kedua gender (unisex), abaikan dari filter "khusus perempuan"
                $unisexPatterns = [
                    '/pria\s*(dan|atau|\/|-)?\s*wanita/i',
                    '/laki\s*(?:-\s*laki)?\s*(dan|atau|\/|-)?\s*perempuan/i',
                    '/putra\s*(dan|atau|\/|-)?\s*putri/i',
                    '/siswa\s*(dan|atau|\/|-)?\s*siswi/i',
                ];
                foreach ($unisexPatterns as $pat) {
                    if (preg_match($pat, $content)) {
                        return false;
                    }
                }
                if (preg_match('/\b(pria|laki[-‑\s]*laki|putra)\b/ui', $content)) {
                    return false;
                }
            }
            if (!empty($criteria['ekonomi']) && !str_contains($content, 'kurang mampu') && !str_contains($content, 'ekonomi') && !str_contains($content, 'kip')) return false;
            if (!empty($criteria['fresh_grad']) && !str_contains($content, 'fresh graduate') && !str_contains($content, 'lulusan baru')) return false;
            if (!empty($criteria['no_interview']) && str_contains($content, 'wawancara')) {
                if (!str_contains($content, 'tanpa wawancara')) return false;
            }

            // --- FILTER PERSYARATAN per kategori (ada/tanpa), deterministik. ---
            if (!empty($criteria['req_filters'])) {
                foreach ($criteria['req_filters'] as $reqKey => $want) {
                    $state = $this->requirementSectionState($r, $reqKey); // 'required' | 'absent'
                    if ($want === 'ada' && $state !== 'required') return false;
                    if ($want === 'tanpa' && $state === 'required') return false;
                }
            }

            // --- FILTER NILAI SPESIFIK per kategori (mis. bahasa "arab", IPK "3.0"). ---
            if (!empty($criteria['req_values'])) {
                foreach ($criteria['req_values'] as $vKey => $vVal) {
                    if (!$this->requirementValueMatches($r, $vKey, $vVal)) return false;
                }
            }

            if (!empty($criteria['lokasi_tipe'])) {
                $negaraLower = strtolower($r->negara ?? '');
                $isActuallyDalam = str_contains($negaraLower, 'indonesia');
                
                if ($criteria['lokasi_tipe'] === 'dalam' && !$isActuallyDalam) return false;
                if ($criteria['lokasi_tipe'] === 'luar' && $isActuallyDalam) return false;
            }

            if (!empty($criteria['negara'])) {
                $m = false;
                $rowNegara = strtolower($r->negara ?? '');
                foreach ($criteria['negara'] as $c) {
                    if (preg_match('/\b' . preg_quote($c, '/') . '\b/i', $rowNegara)) {
                        $m = true;
                        break;
                    }
                }
                if (!$m) return false;
            }
            if (!empty($criteria['benua'])) {
                $m = false;
                $rowBenua = strtolower($r->benua ?? '');
                foreach ($criteria['benua'] as $c) {
                    $searchTerms = [$c];
                    if ($c === 'amerika') $searchTerms[] = 'america';
                    if ($c === 'eropa') $searchTerms[] = 'europe';
                    if ($c === 'australia') $searchTerms[] = 'oceania';

                    foreach ($searchTerms as $term) {
                        if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $rowBenua)) {
                            $m = true;
                            break 2;
                        }
                    }
                }
                if (!$m) return false;
            }
            if (!empty($criteria['jenjang'])) {
                $m = false;
                foreach ($criteria['jenjang'] as $l) {
                    if (str_contains(strtoupper($r->jenjang ?? ''), $l)) {
                        $m = true;
                        break;
                    }
                }
                if (!$m) return false;
            }
            if (!empty($criteria['bulan'])) {
                $m = false;
                $monthsConfig = [
                    'januari' => ['januari', 'january', 'jan'],
                    'februari' => ['februari', 'february', 'pebruari', 'febuari', 'pebuari', 'feb', 'peb'],
                    'maret' => ['maret', 'march', 'mar'],
                    'april' => ['april', 'apr'],
                    'mei' => ['mei', 'may'],
                    'juni' => ['juni', 'june', 'jun'],
                    'juli' => ['juli', 'july', 'jul'],
                    'agustus' => ['agustus', 'august', 'agu', 'agt', 'aug'],
                    'september' => ['september', 'sept', 'sep'],
                    'oktober' => ['oktober', 'october', 'okt', 'oct'],
                    'november' => ['november', 'nopember', 'nov'],
                    'desember' => ['desember', 'december', 'des', 'dec']
                ];

                $deadlineStr = strtolower($r->deadline ?? '');
                foreach ($monthsConfig as $m_key => $variants) {
                    if (in_array($m_key, $criteria['bulan'])) {
                        foreach ($variants as $v) {
                            if (str_contains($deadlineStr, $v)) {
                                $m = true;
                                break 2;
                            }
                        }
                    }
                }
                if (!$m) return false;
            }

            if (!empty($criteria['funding'])) {
                $target = strtolower($criteria['funding']);
                $actual = strtolower($r->kategori ?? '');

                if ($target === 'exchange') {
                    if (!str_contains($actual, 'exchange') && !str_contains($actual, 'pertukaran')) return false;
                } elseif ($target === 'partially funded') {
                    if (!str_contains($actual, 'partially') && !str_contains($actual, 'sebagian') && !str_contains($actual, 'partial')) return false;
                } else {
                    if (!str_contains($actual, 'fully') && !str_contains($actual, 'penuh')) return false;
                }
            }

            // --- FIX FILTER TAHUN ---
            if (!empty($criteria['tahun'])) {
                $matchYear = false;
                $deadlineStr = $r->deadline ?? '';
                foreach ($criteria['tahun'] as $y) {
                    if (str_contains($deadlineStr, $y)) {
                        $matchYear = true;
                        break;
                    }
                }
                if (!$matchYear) return false;
            }
            // ------------------------

            if (!empty($criteria['bidang'])) {
                $m = false;
                $rowBidang = strtolower($r->jurusan ?? '');
                $rowDeskripsi = strtolower($r->deskripsi ?? '');
                $rowPersyaratan = strtolower($r->persyaratan ?? '');
                
                if (str_contains($rowBidang, 'semua') || str_contains($rowBidang, 'all') || str_contains($rowBidang, 'any') || empty($rowBidang) || $rowBidang === '-') {
                    $m = true;
                } else {
                    foreach ($criteria['bidang'] as $b) {
                        $synonyms = $this->getMajorSynonyms($b);
                        foreach ($synonyms as $syn) {
                            if (strlen($syn) <= 3) {
                                $pattern = '/\b' . preg_quote($syn, '/') . '\b/i';
                                if (preg_match($pattern, $rowBidang)) {
                                    $m = true;
                                    break 2;
                                }
                            } else {
                                if (str_contains($rowBidang, $syn) || str_contains($rowDeskripsi, $syn) || str_contains($rowPersyaratan, $syn)) {
                                    $m = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                if (!$m) return false;
            }

            if (!empty($criteria['exclude'])) {
                $ex = $criteria['exclude'];
                $rowNeg = strtolower($r->negara ?? '');
                foreach (($ex['negara'] ?? []) as $xc) {
                    if (preg_match('/\b' . preg_quote($xc, '/') . '\b/i', $rowNeg)) return false;
                }
                $rowBen = strtolower($r->benua ?? '');
                foreach (($ex['benua'] ?? []) as $xc) {
                    if (str_contains($rowBen, $xc)) return false;
                }
                $rowJen = strtoupper($r->jenjang ?? '');
                foreach (($ex['jenjang'] ?? []) as $xc) {
                    if (str_contains($rowJen, strtoupper($xc))) return false;
                }
                $rowJur = strtolower(($r->jurusan ?? '') . ' ' . ($r->deskripsi ?? ''));
                foreach (($ex['bidang'] ?? []) as $xc) {
                    if (str_contains($rowJur, $xc)) return false;
                }
                if (!empty($ex['funding'])) {
                    $rowKat = strtolower($r->kategori ?? '');
                    $isFull = str_contains($rowKat, 'fully') || str_contains($rowKat, 'penuh');
                    $isPart = str_contains($rowKat, 'partially') || str_contains($rowKat, 'sebagian') || str_contains($rowKat, 'partial');
                    if ($ex['funding'] === 'Fully Funded' && $isFull) return false;
                    if ($ex['funding'] === 'Partially Funded' && $isPart) return false;
                }
            }

            if (!empty($criteria['benefit_keywords'])) {
                $area = strtolower(($r->benefit ?? '') . ' ' . ($r->deskripsi ?? '') . ' ' . ($r->kategori ?? '') . ' ' . ($r->persyaratan ?? ''));
                foreach ($criteria['benefit_keywords'] as $kw) {
                    if (!str_contains($area, $kw)) return false;
                }
            }

            if (!empty($criteria['sort_deadline']) || !empty($criteria['still_open']) || !empty($criteria['deadline_before'])) {
                $deadlineTime = $this->parseDeadlineDate($r->deadline ?? '');
                if ($deadlineTime !== null) {
                    // Batas BAWAH: buang beasiswa yang deadline-nya sudah lewat ("masih buka").
                    if ((!empty($criteria['sort_deadline']) || !empty($criteria['still_open'])) && $deadlineTime < time()) {
                        return false;
                    }
                    // Batas ATAS: buang beasiswa yang melewati rentang yang diminta ("sampai akhir tahun / bulan X").
                    if (!empty($criteria['deadline_before']) && $deadlineTime > $criteria['deadline_before']) {
                        return false;
                    }
                }
            }

            if (!empty($criteria['mentioned_location'])) {
                $locPattern = strtolower($criteria['mentioned_location']);
                $searchArea = strtolower(($r->negara ?? '') . ' ' . ($r->benua ?? '') . ' ' . ($r->nama_beasiswa ?? '') . ' ' . ($r->deskripsi ?? ''));
                if (!preg_match('/\b' . preg_quote($locPattern, '/') . '\b/i', $searchArea)) {
                    return false;
                }
            }

            if (!empty($criteria['mentioned_target_group'])) {
                $targetPattern = strtolower($criteria['mentioned_target_group']);
                $searchArea = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? '') . ' ' . ($r->jurusan ?? ''));
                if (!preg_match('/\b' . preg_quote($targetPattern, '/') . '\b/i', $searchArea)) {
                    return false;
                }
            }

            return true;
        });

        $uniqueResults = [];
        $seenNames = [];
        foreach ($filtered as $r) {
            $name = strtolower(trim($r->nama_beasiswa ?? ''));
            if (!in_array($name, $seenNames)) {
                $seenNames[] = $name;
                $uniqueResults[] = $r;
            }
        }

        return $uniqueResults; 
    }

    private function getMajorSynonyms(string $bidang): array
    {
        $bidang = trim(strtolower($bidang));
        $syns = [$bidang];

        if ($bidang === 'it' || $bidang === 'ti' || $bidang === 'teknologi informasi' || $bidang === 'informatika' || $bidang === 'ilmu komputer' || $bidang === 'computer science' || $bidang === 'information technology') {
            return ['it', 'ti', 'teknologi informasi', 'informatika', 'ilmu komputer', 'computer science', 'information technology', 'software', 'programming', 'sistem informasi', 'system innovation', 'computing'];
        }
        if ($bidang === 'kedokteran' || $bidang === 'medicine' || $bidang === 'medis') {
            return ['kedokteran', 'medicine', 'medis', 'medical', 'dokter', 'kesehatan', 'health', 'farmasi', 'pharmacy', 'clinical'];
        }
        if ($bidang === 'hukum' || $bidang === 'law') {
            return ['hukum', 'law', 'legal', 'jurisprudence'];
        }
        if ($bidang === 'ekonomi' || $bidang === 'economy' || $bidang === 'bisnis' || $bidang === 'business' || $bidang === 'manajemen' || $bidang === 'management') {
            return ['ekonomi', 'economy', 'economic', 'bisnis', 'business', 'manajemen', 'management', 'keuangan', 'finance', 'mba'];
        }
        if ($bidang === 'teknik' || $bidang === 'engineering') {
            return ['teknik', 'engineering', 'engineer', 'mechanical', 'civil', 'electrical', 'chemical'];
        }

        return $syns;
    }

    private function generateEmbedding($text)
    {
        $apiKey = trim(env('OPENAI_API_KEY'));
        if (!$apiKey) throw new \Exception("OpenAI API Key is missing.");

        if (str_contains($apiKey, 'sk-or')) {
            $baseUrl = 'https://openrouter.ai/api/v1';
            $model = 'openai/text-embedding-3-small';
        } elseif (str_starts_with($apiKey, 'AIza')) {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
            $response = Http::post("$baseUrl/models/text-embedding-004:embedContent?key=$apiKey", [
                'content' => ['parts' => [['text' => $text]]]
            ]);
            if ($response->successful()) return $response->json()['embedding']['values'];
            throw new \Exception("Gagal koneksi ke Gemini Embedding.");
        } else {
            $baseUrl = 'https://api.openai.com/v1';
            $model = 'text-embedding-3-small';
        }
        
        $response = Http::withToken($apiKey)
            ->connectTimeout(30)
            ->timeout(120) 
            ->retry(3, 1000)
            ->post("$baseUrl/embeddings", [
                'model' => 'openai/text-embedding-3-small',
                'input' => $text,
            ]);

        if ($response->failed()) {
            Log::error("Embedding API Error: " . $response->body());
            throw new \Exception("Gagal koneksi ke server AI (HTTP " . $response->status() . ").");
        }

        $data = $response->json();
        if (!isset($data['data'][0]['embedding'])) {
            Log::error("Embedding API Invalid Response: " . json_encode($data));
            throw new \Exception("Format respon AI tidak valid.");
        }

        return $data['data'][0]['embedding'];
    }

    private function translateToIndonesian($text)
    {
        if (empty($text) || $text === '-' || strlen($text) < 10) return $text;

        $englishKeywords = ['scholarship', 'requirements', 'eligibility', 'benefits', 'citizenship', 'degree', 'deadline', 'tuition', 'award', 'allowance', 'internship', 'public', 'service', 'fees', 'maintenance', 'the', 'and', 'of', 'for', 'with'];
        $isEnglish = false;
        foreach ($englishKeywords as $kw) {
            if (str_contains(strtolower($text), $kw)) {
                $isEnglish = true;
                break;
            }
        }

        if (!$isEnglish) return $text;

        try {
            $apiKey = trim(env('OPENAI_API_KEY'));
            $baseUrl = str_contains($apiKey, 'sk-or') ? 'https://openrouter.ai/api/v1' : 'https://api.openai.com/v1';
            
            $model = str_contains($apiKey, 'sk-or') ? 'google/gemini-2.5-flash-lite' : 'gpt-3.5-turbo';

            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post("$baseUrl/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system', 
                            'content' => 'Anda adalah asisten informasi beasiswa yang profesional. Tugas Anda adalah menerjemahkan (jika teks berbahasa Inggris) dan merapikan teks informasi beasiswa berikut agar sangat mudah dibaca. 
                            Gunakan aturan berikut:
                            1. Gunakan Bahasa Indonesia yang formal dan sopan.
                            2. Gunakan Bold (teks tebal) untuk kategori atau judul kecil.
                            3. Gunakan list/bullet points untuk poin-poin informasi.
                            4. Berikan jarak antar kategori agar tidak menumpuk.
                            5. Hapus bagian yang tidak perlu jika ada pengulangan.
                            Berikan hasil akhirnya saja tanpa komentar tambahan.'
                        ],
                        ['role' => 'user', 'content' => $text]
                    ],
                    'temperature' => 0.3
                ]);

            if ($response->successful()) {
                $result = $response->json()['choices'][0]['message']['content'];
                return trim($result) . "\n\n*(Terjemahan Otomatis)*";
            }
        } catch (\Exception $e) {
            Log::error("Translation Error: " . $e->getMessage());
        }

        return $text; 
    }


    private function buildSessionContext()
    {
        $ctx = [];
        if (session()->has('selected_scholarship')) {
            $s = (array) session()->get('selected_scholarship');
            $ctx[] = "Beasiswa yang sedang dipilih user: \"" . ($s['nama_beasiswa'] ?? '-') . "\" (negara: " . ($s['negara'] ?? '-') . ", jenjang: " . ($s['jenjang'] ?? '-') . ").";
        }
        if (session()->has('last_search_results')) {
            $names = [];
            foreach ((array) session()->get('last_search_results', []) as $i => $r) {
                $r = (array) $r;
                $names[] = ($i + 1) . '. ' . ($r['nama_beasiswa'] ?? '-');
            }
            if ($names) $ctx[] = "Daftar hasil pencarian terakhir:\n" . implode("\n", $names);
        }
        return empty($ctx) ? "(Belum ada konteks percakapan sebelumnya.)" : implode("\n", $ctx);
    }

    private function callChatLLM($systemPrompt, $userMessage, $jsonMode = false, $temperature = 0.0)
    {
        $apiKey = trim(env('OPENAI_API_KEY'));
        if (empty($apiKey)) throw new \Exception("API Key tidak ditemukan.");

        if (str_contains($apiKey, 'sk-or')) {
            $baseUrl = 'https://openrouter.ai/api/v1';
            $model = 'google/gemini-2.5-flash-lite';
        } elseif (str_starts_with($apiKey, 'AIza')) {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
            $model = 'gemini-1.5-flash';
        } else {
            $baseUrl = 'https://api.openai.com/v1';
            $model = 'gpt-3.5-turbo';
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => $temperature,
            'max_tokens' => 2000,
        ];
        if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];

        $response = Http::withToken($apiKey)->timeout(45)->retry(2, 1000)->post("$baseUrl/chat/completions", $payload);
        if ($response->failed()) {
            Log::error("LLM call failed (HTTP {$response->status()}): " . $response->body());
            throw new \Exception("Gagal menghubungi server AI (HTTP " . $response->status() . ").");
        }
        $data = $response->json();
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("Format respon AI tidak valid.");
        }
        return $data['choices'][0]['message']['content'];
    }

    private function understandQuery($message, $sessionContext)
    {
        // 1. Ambil bulan dan tahun dari server secara dinamis
        $bulanIndo = [1 => 'januari', 2 => 'februari', 3 => 'maret', 4 => 'april', 5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'agustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'desember'];
        $bulanSekarang = $bulanIndo[(int)date('m')];
        $tahunSekarang = date('Y');

        // 2. System Prompt LLM Lengkap
        $system = <<<PROMPT
Anda adalah parser niat untuk chatbot pencari BEASISWA berbahasa Indonesia. Tugas Anda HANYA mengubah pesan user menjadi objek JSON. JANGAN menjawab pertanyaan user.

[INFORMASI WAKTU SAAT INI]:
- Bulan: $bulanSekarang
- Tahun: $tahunSekarang
(PENTING: Terjemahkan SEMUA acuan waktu relatif ke nilai absolut berdasarkan info di atas: "bulan ini"/"tahun ini"/"sekarang" -> bulan & tahun saat ini; "tahun depan" -> tahun saat ini + 1; "tahun lalu" -> tahun saat ini - 1; "bulan depan"/"bulan lalu" -> bulan terkait. Masukkan hasilnya ke array "bulan"/"tahun" pada output JSON).

Toleransi typo, singkatan (s2=S2, ln=luar negeri, dn=dalam negeri, dll), bahasa gaul, dan bahasa Inggris. Pahami maksud sebenarnya.

KONTEKS PERCAKAPAN SAAT INI:
$sessionContext

Keluarkan HANYA JSON valid dengan skema:
{
  "intent": "search | detail | validation | next_page | back_to_list | out_of_topic | greeting | thanks | acknowledgment",
  "response": "<HANYA untuk intent greeting/thanks/acknowledgment: kalimat balasan ramah Bahasa Indonesia. Intent lain: null>",
  "detail_type": "benefit | syarat | deadline | funding | url | apply | detail | null",
  "ref_number": <int atau null>,
  "university": "<nama universitas/kampus jika user mencari beasiswa berdasarkan NAMA universitas (mis. Harvard, MIT, Oxford, NUS, Universitas Indonesia, UGM, ITB), atau null>",
  "negara": [<nama negara huruf kecil>],
  "benua": [<eropa|asia|amerika|afrika|australia>],
  "jenjang": [<S1|S2|S3|D3|D4>],
  "bidang": [<nama jurusan huruf kecil>],
  "bulan": [<nama bulan indonesia huruf kecil>],
  "tahun": [<"2024".."2027">],
  "funding": "Fully Funded | Partially Funded | Exchange | null",
  "lokasi_tipe": "luar | dalam | null",
  "sort_deadline": "asc | desc | null",
  "still_open": <true|false>,
  "deadline_before": "<tanggal akhir rentang format YYYY-MM-DD, atau null>",
  "benefit_keywords": [<frasa benefit spesifik: "tiket pesawat","uang saku","biaya hidup","biaya kuliah","duolingo","ielts","toefl", dll>],
  "flags": { "tanpa_test_bahasa": false, "ekonomi_lemah": false, "khusus_perempuan": false, "fresh_graduate": false, "tanpa_wawancara": false },
  "req_filters": {
    "usia": "ada | tanpa | null",
    "ipk": "ada | tanpa | null",
    "tes_bahasa_inggris": "ada | tanpa | null",
    "kewarganegaraan": "ada | tanpa | null",
    "bahasa_lain": "ada | tanpa | null",
    "tes_standar": "ada | tanpa | null",
    "dokumen": "ada | tanpa | null",
    "khusus": "ada | tanpa | null"
  },
  "req_values": {
    "usia": "<angka usia user, mis. \"25\", atau null>",
    "ipk": "<angka IPK user, mis. \"3.0\", atau null>",
    "tes_bahasa_inggris": "<nama tes + skor jika disebut, mis. \"toefl 80\", \"ielts\", atau null>",
    "kewarganegaraan": "<nama negara/kewarganegaraan, mis. \"indonesia\", atau null>",
    "bahasa_lain": "<nama bahasa, mis. \"arab\", \"jepang\", \"mandarin\", atau null>",
    "tes_standar": "<nama tes, mis. \"gre\", \"gmat\", \"sat\", atau null>",
    "dokumen": "<jenis dokumen, mis. \"transkrip\", \"surat rekomendasi\", atau null>",
    "khusus": "<frasa syarat khusus, atau null>"
  },
  "query_scope": "new_search | follow_up | null",
  "exclude": { "negara": [], "benua": [], "jenjang": [], "bidang": [], "funding": null }
}

ATURAN PENTING:
- intent "search": user mencari/minta daftar beasiswa dengan kriteria apa pun.
- intent "detail": user menanyakan benefit/syarat/deadline/cara daftar/link dari beasiswa yang SUDAH dipilih (lihat konteks). Isi detail_type.
- DETAIL_TYPE MAPPING: jika intent adalah "detail", petakan detail_type dengan tepat berdasarkan aspek yang ditanyakan:
  - "syarat": jika menanyakan skor TOEFL/IELTS, IPK, batas usia, dokumen (transkrip/rekomendasi), wawancara, kewarganegaraan, dll (contoh: "skor toefl nya harus berapa", "butuh ielts berapa", "ipk minimal berapa", "ada wawancara ga").
  - "benefit": jika menanyakan uang saku, tiket pesawat, biaya kuliah, akomodasi, asuransi, dll (contoh: "dapat uang saku ga", "dapat apa saja", "tunjangannya apa").
- ref_number: nomor urut beasiswa pada daftar yang dirujuk user. WAJIB tangkap bentuk ANGKA ("1","2","no 3") MAUPUN kata bilangan/urutan ("satu","dua","tiga","pertama","kedua","ketiga","yang pertama","yg kedua", dst) lalu ubah ke int (satu/pertama=1, dua/kedua=2, tiga/ketiga=3, dst).
- PEMILIHAN ITEM: jika user HANYA memilih sebuah item dari daftar (mis. "satu", "dua", "yang ketiga", "pilih nomor 2", "nomor 1") TANPA menyebut aspek tertentu -> intent "detail", detail_type "detail", dan isi ref_number.
- intent "validation": user bertanya YA/TIDAK tentang beasiswa yang sedang dipilih/dirujuk (mis. "apakah ini di jepang?", "ada jurusan kedokteran ga?"). Isi kriteria yang divalidasi (negara/benua/jenjang/bidang/funding). KHUSUS pertanyaan yes/no tentang SATU kategori SYARAT dari beasiswa terpilih (mis. "ada syarat IPK?", "perlu TOEFL ga?", "ada batasan usia?", "wajib bahasa lain?") -> intent "validation" DAN isi req_filters[kategori]="ada" untuk kategori yang ditanyakan (ipk/tes_bahasa_inggris/usia/kewarganegaraan/bahasa_lain/tes_standar/dokumen/khusus).
- intent "next_page": user minta MELANJUTKAN daftar hasil sebelumnya / melihat lebih banyak (mis. "yang lain", "selanjutnya", "berikutnya", "ada lagi", "tampilkan lagi", "lainnya"). WAJIB toleran typo: "yang laib", "slanjutnya", "lainnyaa", "ada lg" -> tetap next_page. JANGAN isi kriteria baru. PENTING: kata "lain" sebagai bagian dari KRITERIA pencarian — mis. "bahasa lain", "syarat lain", "jurusan lain", "negara lain" — BUKAN next_page; itu intent "search". next_page HANYA bila user benar-benar minta melanjutkan daftar tanpa kriteria baru.
- intent "back_to_list": user minta KEMBALI ke daftar beasiswa sebelumnya (mis. "kembali", "balik", "list sebelumnya", "daftar tadi"). Toleran typo. JANGAN isi kriteria baru.
- intent "out_of_topic": HANYA untuk pertanyaan yang JELAS di luar topik beasiswa/pendidikan (mis. "resep nasi goreng", "cuaca hari ini") atau nonsense ("beasiswa warnanya apa"). PENTING: Pertanyaan tentang administrasi/proses PASCA-KELULUSAN (misal: "cara membuat visa", "membuat paspor", "imigrasi") WAJIB dikategorikan sebagai "out_of_topic", MESKIPUN pengguna menyebutkan nomor beasiswa secara eksplisit (contoh: "cara membuat visa beasiswa nomor 2"). JANGAN gunakan untuk sapaan, basa-basi, atau frasa minta-izin bertanya. Pertanyaan mencari beasiswa berdasarkan gender (mis. "khusus perempuan/wanita"), ekonomi lemah, atau status kelulusan (fresh graduate) adalah VALID dan masuk intent "search", BUKAN "out_of_topic".
- intent "greeting": sapaan ("halo", "pagi", "assalamualaikum") DAN frasa minta-izin/meta bertanya ("mau tanya dong", "izin bertanya kak", "boleh nanya nggak", "mau konsultasi", "halo mau tanya"). Toleran typo. Frasa minta-izin TIDAK PERNAH out_of_topic.
- intent "thanks": ucapan terima kasih murni ("makasih", "terima kasih ya").
- intent "acknowledgment": konfirmasi/penerimaan singkat ("oke", "siap", "baik", "paham", "mengerti").
- PENTING: untuk intent greeting/thanks/acknowledgment, WAJIB isi field "response" dengan kalimat balasan ramah Bahasa Indonesia yang relevan (mis. greeting → mempersilakan user bertanya seputar beasiswa; thanks → balasan terima kasih; acknowledgment → balasan singkat & ramah). Ini SATU-SATUNYA pengecualian dari aturan "JANGAN menjawab pertanyaan user". Untuk intent LAIN, "response" = null.
- NEGARA: masukkan SEMUA nama tempat/negara yang user sebut ke "negara" (huruf kecil), TERMASUK yang tidak umum atau fiktif (mis. "wakanda", "atlantis", "antartika"), supaya ketersediaannya bisa divalidasi. "benua" HANYA boleh berisi: eropa, asia, amerika, afrika, australia; tempat lain masukkan ke "negara".
- UNIVERSITAS: jika user mencari beasiswa berdasarkan NAMA UNIVERSITAS/KAMPUS sebagai acuan (mis. "beasiswa di Harvard", "beasiswa MIT", "beasiswa Universitas Indonesia", "beasiswa UGM/ITB/NUS/Oxford"), isi "university" dengan nama universitas itu, dan JANGAN masukkan nama universitas tersebut ke "negara"/"benua"/"bidang". CATATAN: ini HANYA untuk nama institusi/kampus, BUKAN nama program beasiswa (Chevening, LPDP, Erasmus, AAS, Fulbright, dll tetap pencarian biasa dengan "university": null).
- NEGASI: "selain/bukan/kecuali/tanpa negara X" -> masukkan ke "exclude", JANGAN ke kriteria utama.
- "fully funded/gratis/pendanaan penuh/biaya penuh" -> funding "Fully Funded". "partially/sebagian/parsial" -> "Partially Funded".
- "exchange/pertukaran pelajar/student exchange/program pertukaran/exchange program" -> funding "Exchange".
- "deadline terdekat/paling dekat/segera tutup" -> sort_deadline "asc". "masih buka/belum lewat/aktif/sedang dibuka" -> still_open true.
- WAKTU RENTANG: Jika user meminta rentang waktu (misal: "sampai akhir tahun", "sampai bulan maret", "beberapa bulan ke depan"), KOSONGKAN array "bulan", set "still_open": true, DAN isi "deadline_before" dengan tanggal akhir rentang format YYYY-MM-DD (contoh: "sampai akhir tahun" -> "$tahunSekarang-12-31", "sampai bulan maret" -> "$tahunSekarang-03-31").
- Hanya isi tahun "2024".."2027". Kosongkan array jika tidak disebut.
- FILTER PERSYARATAN (req_filters): isi HANYA jika user menyinggung kategori persyaratan. Untuk tiap kategori, "ada" = user mau beasiswa yang MENSYARATKAN kategori itu; "tanpa" = user mau beasiswa TANPA syarat itu; null jika tak disebut.
  - usia: "ada syarat usia/umur" -> "ada"; "tanpa batas usia/bebas umur" -> "tanpa".
  - ipk: "ada syarat ipk/gpa minimal" -> "ada"; "tanpa ipk/bebas ipk/tanpa minimum gpa" -> "tanpa".
  - tes_bahasa_inggris: "wajib toefl/ielts/tes bahasa inggris" -> "ada"; "tanpa toefl/ielts/tes bahasa inggris" -> "tanpa".
  - kewarganegaraan: "khusus WNI / syarat kewarganegaraan" -> "ada"; "tanpa syarat kewarganegaraan" -> "tanpa".
  - bahasa_lain: "dengan syarat bahasa lain/bahasa asing (selain Inggris: jepang, jerman, korea, dll), jlpt, dele, hsk" -> "ada"; "tanpa bahasa lain" -> "tanpa".
  - tes_standar: "wajib GRE/GMAT/SAT" -> "ada"; "tanpa GRE/GMAT/SAT/tes standar" -> "tanpa".
  - dokumen: "ada syarat dokumen tertentu (transkrip, ijazah, cv)" -> "ada"; "tanpa dokumen khusus" -> "tanpa".
  - khusus: persyaratan lain (surat rekomendasi, motivation letter, essay, LoA, pengalaman. CATATAN: Untuk wawancara/interview, JANGAN gunakan khusus; gunakan flags.tanpa_wawancara) -> "ada"/"tanpa".
- NILAI SPESIFIK (req_values): jika user menyebut NILAI tertentu untuk sebuah kategori, isi req_values dengan nilai itu (huruf kecil). Contoh: "beasiswa berbahasa arab" -> bahasa_lain "arab"; "syarat JLPT / HSK / TOPIK" -> bahasa_lain "jepang" / "mandarin" / "korea"; "IPK saya 3.0" / "syarat ipk 3.0" -> ipk "3.0"; "untuk usia 25 tahun" -> usia "25"; "yang minta GRE" -> tes_standar "gre"; "wajib transkrip" -> dokumen "transkrip"; "surat rekomendasi" -> dokumen "rekomendasi"; "khusus WNI" -> kewarganegaraan "indonesia"; "toefl 80" -> tes_bahasa_inggris "toefl 80". Jika kategori tidak menyebut nilai spesifik, isi null. Saat req_values terisi, kategori itu otomatis dianggap "ada" (tidak perlu set req_filters juga).
- FLAGS: set true pada key yang sesuai jika user meminta kriteria khusus berikut:
  - tanpa_test_bahasa: jika mencari beasiswa tanpa toefl/ielts/tes bahasa inggris.
  - ekonomi_lemah: jika mencari beasiswa untuk keluarga kurang mampu, ekonomi lemah, penerima KIP, dll.
  - khusus_perempuan: jika mencari beasiswa khusus wanita/perempuan/mahasiswi/putri.
  - fresh_graduate: jika mencari beasiswa untuk lulusan baru / fresh graduate.
  - tanpa_wawancara: jika mencari beasiswa tanpa proses wawancara atau tanpa wawancara.
- NEGASI SYARAT: Jika user meminta beasiswa "tanpa [syarat]" (misal: "tanpa toefl", "tanpa ipk", "tanpa wawancara", "tanpa bahasa jepang"), set req_filters untuk kategori terkait menjadi "tanpa". JANGAN masukkan nilai tersebut ke req_values. Contoh: "tanpa bahasa jepang" -> req_filters.bahasa_lain = "tanpa" (JANGAN isi req_values.bahasa_lain dengan "jepang").
- BAHASA vs NEGARA: Jika user menyebut kata yang bisa merujuk ke negara maupun bahasa (misal: "jepang", "jerman", "prancis", "spanyol", "arab"), perhatikan konteksnya. Jika merujuk ke kemampuan/syarat bahasa (contoh: "wajib bisa bahasa jerman", "sertifikat bahasa jerman", "lancar bahasa spanyol"), masukkan ke req_values.bahasa_lain. JANGAN masukkan ke "negara" kecuali user secara eksplisit juga menyebutkan lokasinya (misal: "di Jerman").
- QUERY_SCOPE: berdasarkan KONTEKS PERCAKAPAN, tentukan apakah pesan ini "follow_up" (MELANJUTKAN pencarian sebelumnya: menyempitkan/menambah/mengganti SATU kriteria pada hasil sebelumnya TANPA menyebut ulang kata "beasiswa", mis. "yang di jepang dong", "yang fully funded aja", "khusus S2") atau "new_search" (pencarian BARU yang berdiri sendiri, mis. "carikan beasiswa S2 di jerman", "beasiswa dengan syarat bahasa lain ada ga"). ATURAN: jika pesan menyebut kata "beasiswa" dan menyatakan kriteria lengkapnya sendiri, ATAU diawali kata cari/carikan/tampilkan/temukan -> SELALU "new_search" (walau ada konteks sebelumnya). Jika belum ada konteks pencarian sebelumnya ATAU intent bukan search, isi null.
- Keluarkan JSON saja, tanpa penjelasan, tanpa markdown.
PROMPT;

        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $raw = $this->callChatLLM($system, $message, true, 0.0);
                $raw = trim($raw);
                $raw = preg_replace('/```(?:json)?/i', '', $raw);
                $raw = trim(str_replace('```', '', $raw));
                $json = json_decode($raw, true);
                if (!is_array($json) || empty($json['intent'])) {
                    throw new \Exception("Ekstraksi LLM tidak valid (attempt $attempt): " . substr($raw, 0, 100));
                }
                return $json;
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                sleep(2); // Wait 2 seconds before retrying
            }
        }
    }

    private function mapLlmToCriteria($j)
    {
        $arr = fn($v) => is_array($v) ? array_values(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $v), fn($x) => $x !== '')) : [];

        $c = [
            'negara' => $arr($j['negara'] ?? []),
            'benua' => $arr($j['benua'] ?? []),
            'jenjang' => array_values(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)), (array)($j['jenjang'] ?? [])), fn($x) => in_array($x, ['S1','S2','S3','D3','D4']))),
            'bulan' => $arr($j['bulan'] ?? []),
            'tahun' => array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($j['tahun'] ?? [])), fn($x) => preg_match('/^20[0-9]{2}$/', $x))),
            'semester' => null,
            'funding' => null,
            'negara_ori' => null,
            'bidang' => $arr($j['bidang'] ?? []),
            'lokasi_tipe' => null,
        ];

        $uni = trim((string)($j['university'] ?? ''));
        $c['university'] = ($uni !== '' && strtolower($uni) !== 'null') ? $uni : null;

        $fund = strtolower((string)($j['funding'] ?? ''));
        if (str_contains($fund, 'exchange') || str_contains($fund, 'pertukaran')) $c['funding'] = 'Exchange';
        elseif (str_contains($fund, 'full') || str_contains($fund, 'penuh')) $c['funding'] = 'Fully Funded';
        elseif (str_contains($fund, 'partial') || str_contains($fund, 'sebagian') || str_contains($fund, 'parsial')) $c['funding'] = 'Partially Funded';

        $lok = strtolower((string)($j['lokasi_tipe'] ?? ''));
        if ($lok === 'luar') $c['lokasi_tipe'] = 'luar';
        elseif ($lok === 'dalam') { $c['lokasi_tipe'] = 'dalam'; if (!in_array('indonesia', $c['negara'])) $c['negara'][] = 'indonesia'; }

        // --- FIX LOKASI CAMPURAN ---
        if (count($c['negara']) > 1 && in_array('indonesia', $c['negara'])) {
            $c['lokasi_tipe'] = null;
        }
        // ---------------------------

        if (!empty($c['negara'])) $c['negara_ori'] = ucwords($c['negara'][0]);
        $sort = strtolower((string)($j['sort_deadline'] ?? ''));
        if ($sort === 'asc' || $sort === 'desc') { $c['sort_deadline'] = true; $c['sort_deadline_dir'] = $sort; }
        if (!empty($j['still_open'])) $c['still_open'] = true;
        if (!empty($j['deadline_before'])) {
            $t = strtotime((string)$j['deadline_before']);
            if ($t !== false) $c['deadline_before'] = $t + 86399; // inklusif sampai akhir hari
        }

        $c['benefit_keywords'] = $arr($j['benefit_keywords'] ?? []);

        $f = $j['flags'] ?? [];
        if (!empty($f['tanpa_test_bahasa'])) $c['no_test'] = true;
        if (!empty($f['ekonomi_lemah'])) $c['ekonomi'] = true;
        if (!empty($f['khusus_perempuan'])) $c['gender'] = 'perempuan';
        if (!empty($f['fresh_graduate'])) $c['fresh_grad'] = true;
        if (!empty($f['tanpa_wawancara'])) $c['no_interview'] = true;

        // --- FILTER PERSYARATAN (req_filters): nilai per kategori = 'ada' | 'tanpa' | null. ---
        $reqFilters = [];
        $rawReq = is_array($j['req_filters'] ?? null) ? $j['req_filters'] : [];
        foreach (self::REQUIREMENT_SECTION_MAP as $key => $_) {
            $v = strtolower(trim((string) ($rawReq[$key] ?? '')));
            if ($v === 'ada' || $v === 'tanpa') $reqFilters[$key] = $v;
        }
        // Legacy flag "tanpa_test_bahasa" -> samakan ke req_filters tes_bahasa_inggris = 'tanpa'.
        if (!empty($f['tanpa_test_bahasa'])) $reqFilters['tes_bahasa_inggris'] = 'tanpa';
        // Sebaliknya, req_filters tes_bahasa_inggris = 'tanpa' juga aktifkan jalur no_test
        // (pembersihan query IELTS/TOEFL di handleSearch / "FIX VECTOR PARADOX").
        if (($reqFilters['tes_bahasa_inggris'] ?? null) === 'tanpa') $c['no_test'] = true;

        // --- NILAI SPESIFIK per kategori (req_values): mis. bahasa_lain="arab", ipk="3.0". ---
        $reqValues = [];
        $rawVal = is_array($j['req_values'] ?? null) ? $j['req_values'] : [];
        foreach (self::REQUIREMENT_SECTION_MAP as $key => $_) {
            $v = strtolower(trim((string) ($rawVal[$key] ?? '')));
            if ($v !== '' && $v !== 'null') {
                $reqValues[$key] = $v;
                unset($reqFilters[$key]); // nilai spesifik menggantikan ada/tanpa utk kategori ini.
            }
        }
        if (!empty($reqValues)) $c['req_values'] = $reqValues;
        if (!empty($reqFilters)) $c['req_filters'] = $reqFilters;

        // --- QUERY SCOPE: penentu sesi baru vs lanjutan (sepenuhnya dari LLM). ---
        $scope = strtolower(trim((string) ($j['query_scope'] ?? '')));
        $c['query_scope'] = ($scope === 'follow_up' || $scope === 'new_search') ? $scope : null;

        // --- FIX KONTRADIKSI LLM ---
        if (!empty($c['no_test'])) {
            $c['benefit_keywords'] = array_values(array_filter($c['benefit_keywords'], function($kw) {
                return !str_contains(strtolower($kw), 'ielts') && !str_contains(strtolower($kw), 'toefl');
            }));
            $c['bidang'] = array_values(array_filter($c['bidang'], function($kw) {
                return !str_contains(strtolower($kw), 'ielts') && !str_contains(strtolower($kw), 'toefl');
            }));
        }
        // ---------------------------

        $ex = $j['exclude'] ?? [];
        $c['exclude'] = [
            'negara' => $arr($ex['negara'] ?? []),
            'benua' => $arr($ex['benua'] ?? []),
            'jenjang' => array_values(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)), (array)($ex['jenjang'] ?? [])), fn($x) => $x !== '')),
            'bidang' => $arr($ex['bidang'] ?? []),
            'funding' => null,
        ];
        $exf = strtolower((string)($ex['funding'] ?? ''));
        if (str_contains($exf, 'full') || str_contains($exf, 'penuh')) $c['exclude']['funding'] = 'Fully Funded';
        elseif (str_contains($exf, 'partial') || str_contains($exf, 'sebagian')) $c['exclude']['funding'] = 'Partially Funded';

        return $c;
    }

    private function handleValidationQuery($criteria, $targetScholarship, $rawMessage)
    {
        $nama = $targetScholarship['nama_beasiswa'] ?? 'Beasiswa';

        if (!empty($criteria['bidang'])) {
            $matchedMajors = [];
            $bidang = strtolower($targetScholarship['jurusan'] ?? '');
            $persyaratan = strtolower($targetScholarship['persyaratan'] ?? '');
            $benefit = strtolower($targetScholarship['benefit'] ?? '');
            foreach ($criteria['bidang'] as $b) {
                if (str_contains($bidang, $b) || str_contains($persyaratan, $b) || str_contains($benefit, $b)) $matchedMajors[] = ucwords($b);
            }
            $this->currentIntent = 'validation_major';
            if (!empty($matchedMajors)) return $this->finalizeResponse("Iya benar, beasiswa **$nama** tersedia untuk jurusan **" . implode(', ', $matchedMajors) . "**. 😊");
            return $this->finalizeResponse("Mohon maaf, sepertinya beasiswa **$nama** tidak secara spesifik menyebutkan ketersediaan untuk jurusan **" . implode(', ', array_map('ucwords', $criteria['bidang'])) . "**. 😊");
        }

        if (!empty($criteria['jenjang'])) {
            $matchedLevels = [];
            $rowJenjang = strtoupper($targetScholarship['jenjang'] ?? '');
            foreach ($criteria['jenjang'] as $l) { if (str_contains($rowJenjang, $l)) $matchedLevels[] = $l; }
            $this->currentIntent = 'validation_jenjang';
            if (!empty($matchedLevels)) return $this->finalizeResponse("Iya benar, beasiswa **$nama** tersedia untuk jenjang **" . implode('/', $matchedLevels) . "**. 😊");
            return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak tersedia untuk jenjang " . implode('/', $criteria['jenjang']) . ". Jenjang yang tersedia adalah **" . ($targetScholarship['jenjang'] ?? '-') . "**. 😊");
        }

        if (!empty($criteria['negara'])) {
            $matchedCountries = [];
            $rowNegara = strtolower($targetScholarship['negara'] ?? '');
            foreach ($criteria['negara'] as $cc) { if (preg_match('/\b' . preg_quote($cc, '/') . '\b/i', $rowNegara)) $matchedCountries[] = ucwords($cc); }
            $this->currentIntent = 'validation_negara';
            if (!empty($matchedCountries)) return $this->finalizeResponse("Iya benar, beasiswa **$nama** berlokasi di **" . implode(', ', $matchedCountries) . "**. 😊");
            return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak berlokasi di " . implode(', ', array_map('ucwords', $criteria['negara'])) . ". Lokasi aslinya adalah di **" . ($targetScholarship['negara'] ?? '-') . "**. 😊");
        }

        if (!empty($criteria['benua'])) {
            $matchedContinents = [];
            $rowBenua = strtolower($targetScholarship['benua'] ?? '');
            foreach ($criteria['benua'] as $cc) {
                $searchTerms = [$cc];
                if ($cc === 'amerika') $searchTerms[] = 'america';
                if ($cc === 'eropa') $searchTerms[] = 'europe';
                if ($cc === 'australia') $searchTerms[] = 'oceania';
                foreach ($searchTerms as $term) { if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $rowBenua)) { $matchedContinents[] = ucwords($cc); break; } }
            }
            $this->currentIntent = 'validation_benua';
            if (!empty($matchedContinents)) return $this->finalizeResponse("Iya benar, beasiswa **$nama** berlokasi di benua **" . implode(', ', $matchedContinents) . "**. 😊");
            return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak berada di benua " . implode(', ', array_map('ucwords', $criteria['benua'])) . ". Benua aslinya adalah **" . ucwords($targetScholarship['benua'] ?? '-') . "**. 😊");
        }

        if (!empty($criteria['funding'])) {
            $targetFund = strtolower($criteria['funding']);
            $actualFund = strtolower($targetScholarship['kategori'] ?? '');
            $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $rawMessage);
            $bothFunded = (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) && (str_contains($actualFund, 'partially') || str_contains($actualFund, 'sebagian') || str_contains($actualFund, 'partial'));
            if ($isEnglishQuery) {
                $actualDisplay = 'Partially Funded';
                if ($bothFunded) $actualDisplay = 'Fully & Partially Funded';
                elseif (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) $actualDisplay = 'Fully Funded';
                $targetDisplay = ($targetFund === 'fully funded') ? 'Fully Funded' : 'Partially Funded';
            } else {
                $actualDisplay = 'Pendanaan Sebagian (Parsial)';
                if ($bothFunded) $actualDisplay = 'Pendanaan Penuh & Sebagian';
                elseif (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) $actualDisplay = 'Pendanaan Penuh (Full Gratis)';
                $targetDisplay = ($targetFund === 'fully funded') ? 'Pendanaan Penuh' : 'Pendanaan Sebagian';
            }
            $isMatched = ($targetFund === 'fully funded')
                ? (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh'))
                : (str_contains($actualFund, 'partially') || str_contains($actualFund, 'sebagian') || str_contains($actualFund, 'partial'));
            $this->currentIntent = 'validation_funding';
            if ($isMatched) return $this->finalizeResponse("Iya benar, beasiswa **$nama** kategorinya adalah **$actualDisplay**. 😊");
            return $this->finalizeResponse("Bukan, beasiswa **$nama** kategorinya adalah **$actualDisplay**, bukan $targetDisplay. 😊");
        }

        if (!empty($criteria['no_interview']) || preg_match('/\b(wawancara|interview)\b/i', $rawMessage)) {
            $this->currentIntent = 'validation_wawancara';
            $content = strtolower(($targetScholarship['nama_beasiswa'] ?? '') . ' ' . ($targetScholarship['persyaratan'] ?? '') . ' ' . ($targetScholarship['deskripsi'] ?? ''));
            $hasInterview = str_contains($content, 'wawancara') && !str_contains($content, 'tanpa wawancara');
            
            if ($hasInterview) {
                return $this->finalizeResponse("Beasiswa **$nama** mensyaratkan proses wawancara. 😊\n\nKetik **syarat** untuk melihat persyaratan lengkapnya, atau **kembali** untuk kembali to daftar beasiswa.");
            } else {
                return $this->finalizeResponse("Beasiswa **$nama** ini tanpa proses wawancara. 😊\n\nKetik **syarat** untuk melihat persyaratan lengkapnya, atau **kembali** untuk kembali to daftar beasiswa.");
            }
        }

        return null;
    }

    private function handlePureAI($message, $ragEnabled = false, $normalizedData = null, $forceContext = false)
    {
        try {
            $apiKey = trim(env('OPENAI_API_KEY'));
            
            if (empty($apiKey)) {
                return $this->finalizeResponse("Maaf, konfigurasi API Key tidak ditemukan. Silakan hubungi admin.", $normalizedData, false);
            }

            if (str_contains($apiKey, 'sk-or')) {
                $baseUrl = 'https://openrouter.ai/api/v1';
                $model = 'google/gemini-2.5-flash-lite';
            } elseif (str_starts_with($apiKey, 'AIza')) {
                $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
                $model = 'gemini-1.5-flash';
            } else {
                $baseUrl = 'https://api.openai.com/v1';
                $model = 'gpt-3.5-turbo';
            }

            $systemPrompt = 'Anda adalah ScholarBot, asisten AI informasi beasiswa. PENTING: Jika memberikan daftar atau rekomendasi beasiswa, berikan MAKSIMAL 5 beasiswa saja (jangan terlalu banyak). ';
            
            if ($forceContext) {
                $systemPrompt .= 'Anda adalah ahli beasiswa. Berikan jawaban yang sangat detail dan akurat mengenai beasiswa yang ditanyakan oleh user. Gunakan pengetahuan luas Anda karena data di database kami sedang tidak lengkap. PENTING: Jika memberikan daftar atau rekomendasi beasiswa, berikan MAKSIMAL 5 beasiswa saja.';
            } elseif ($ragEnabled) {
                $context = $this->getScholarshipContext($message);
                
                if (str_contains($context, 'Tidak ada data spesifik') || str_contains($context, 'Gagal mengambil data')) {
                    return $this->finalizeResponse("Mohon maaf, informasi mengenai beasiswa tersebut belum tersedia di database kami. Silakan nonaktifkan toggle RAG di atas untuk bertanya secara luas. 😊", $normalizedData);
                }

                $systemPrompt .= 'Saat ini Anda beroperasi dalam MODE RAG. 
                Tugas Anda:
                1. Berikan informasi beasiswa HANYA berdasarkan Context Beasiswa yang diberikan di bawah.
                2. Jika informasi tidak ada di context, Anda WAJIB menolak dengan menyatakan bahwa informasi beasiswa tersebut belum tersedia di database kami. JANGAN menggunakan pengetahuan luar Anda.
                3. JIKA memberikan rekomendasi atau daftar beasiswa, berikan MAKSIMAL 5 beasiswa saja.
                4. JIKA pertanyaan user di luar topik beasiswa, pendidikan, atau universitas, Anda WAJIB menolak dengan kalimat persis seperti ini:
                   "Mohon maaf, chatbot kami tidak menerima pertanyaan diluar informasi beasiswa, jika ingin bertanya hal tersebut bisa anda off kan toggle diatas dan silahkan ulangi pertanyaannya."';
                
                $systemPrompt .= "\n\nContext Beasiswa dari Dataset Kami:\n" . $context;
                $systemPrompt .= "\n\nINSTRUKSI PENTING:\n";
                $systemPrompt .= "1. Jawablah secara singkat dan akurat HANYA berdasarkan context di atas.\n";
                $systemPrompt .= "2. Jika tidak ada di context, katakan secara jujur bahwa informasi tersebut belum tersedia di database kami.\n";
                $systemPrompt .= "3. Batasi daftar beasiswa maksimal 5 saja.\n";
                $systemPrompt .= "4. ATURAN JAWABAN YA/TIDAK: Jika user bertanya apakah suatu beasiswa 'tanpa IELTS' atau 'tidak mensyaratkan IELTS', dan data di context membenarkan hal itu, maka Anda HARUS menjawab dengan persetujuan positif: 'Iya, beasiswa ini tanpa IELTS (tidak mensyaratkan tes bahasa Inggris)'. DILARANG KERAS menjawab dengan kata 'Tidak' karena maknanya akan ambigu.";
            } else {
                $systemPrompt .= 'Saat ini Anda beroperasi dalam MODE STANDAR (General AI). 
                Jawablah pertanyaan user secara bebas dan ramah tentang TOPIK APAPUN. 
                Anda tidak perlu membatasi diri pada beasiswa karena Mode RAG sedang dimatikan.
                PENTING: Jika memberikan daftar atau rekomendasi beasiswa, berikan MAKSIMAL 5 beasiswa saja.';
            }

            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post("$baseUrl/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system', 
                            'content' => $systemPrompt
                        ],
                        ['role' => 'user', 'content' => $message]
                    ],
                    'temperature' => 0.7
                ]);

            if ($response->successful()) {
                $ans = $response->json()['choices'][0]['message']['content'];
                
                if ($ragEnabled) {
                    $ans .= "\n\n*(Mode: RAG - Dataset Context)*";
                } else {
                    $ans .= "\n\n*(Mode: Pure AI - Tanpa Dataset)*";
                }

                return $this->finalizeResponse($ans, $normalizedData);
            } else {
                Log::error("Gemini API Error: " . $response->body());
                return $this->finalizeResponse("Maaf, terjadi gangguan pada server AI (Gemini). Alasan: " . ($response->json()['error']['message'] ?? 'Unknown Error'), $normalizedData, false);
            }

        } catch (\Exception $e) {
            Log::error("Pure AI Error: " . $e->getMessage());
            return $this->finalizeResponse("Terjadi kesalahan saat menghubungi server AI. Silakan periksa koneksi atau API Key Anda.", $normalizedData, false);
        }
    }

    private function handleMajorValidation($major, $normalizedData, $ref = null)
    {
        $major = strtolower(trim($major));
        $ref = strtolower(trim($ref ?? ''));

        if (preg_match('/\b(?:no|nomor|#|ke)\s*([0-9]+)\b/i', $ref, $refMatches)) {
            $num = (int)$refMatches[1];
            $results = session()->get('last_search_all_results', []);
            if (isset($results[$num - 1])) {
                $s = (array)$results[$num - 1];
                $bidang = strtolower($s['jurusan'] ?? '');
                $persyaratan = strtolower($s['persyaratan'] ?? '');
                $benefit = strtolower($s['benefit'] ?? '');
                $nama = $s['nama_beasiswa'];
                
                if (str_contains($bidang, $major) || str_contains($persyaratan, $major) || str_contains($benefit, $major)) {
                    return $this->finalizeResponse("Iya, beasiswa nomor $num (**$nama**) tersedia untuk jurusan **" . ucwords($major) . "**. 😊\n\nSilakan pilih nomor $num untuk melihat detail lengkapnya.", $normalizedData);
                } else {
                    return $this->finalizeResponse("Mohon maaf, beasiswa nomor $num (**$nama**) sepertinya tidak tersedia untuk jurusan **" . ucwords($major) . "**. 😊", $normalizedData);
                }
            }
        }

        if (session()->has('selected_scholarship') && ($ref === 'ini' || $ref === 'itu' || $ref === 'tersebut' || $ref === '')) {
            $s = session()->get('selected_scholarship');
            $bidang = strtolower($s['bidang'] ?? '');
            $persyaratan = strtolower($s['persyaratan'] ?? '');
            $benefit = strtolower($s['benefit'] ?? '');
            $nama = $s['nama_beasiswa'];
            
            if (str_contains($bidang, $major) || str_contains($persyaratan, $major) || str_contains($benefit, $major)) {
                return $this->finalizeResponse("Iya, beasiswa **$nama** tersedia untuk jurusan **" . ucwords($major) . "**. 😊\n\nApa lagi yang ingin Anda ketahui? (Ketik: **Benefit**, **Syarat**, **Deadline**, atau **Cara Daftar**)", $normalizedData);
            } else {
                return $this->finalizeResponse("Mohon maaf, sepertinya beasiswa **$nama** tidak secara spesifik menyebutkan ketersediaan untuk jurusan **" . ucwords($major) . "**. Namun Anda bisa mencoba mengecek detail syarat lengkapnya dengan mengetik **'Syarat'** atau mencari beasiswa lain. 😊", $normalizedData);
            }
        }

        $results = session()->get('last_search_results', []);
        $foundIn = [];

        foreach ($results as $i => $s) {
            $s = (array)$s;
            $bidang = strtolower($s['bidang'] ?? '');
            $persyaratan = strtolower($s['persyaratan'] ?? '');
            $benefit = strtolower($s['benefit'] ?? '');
            
            if (str_contains($bidang, $major) || str_contains($persyaratan, $major) || str_contains($benefit, $major)) {
                $foundIn[] = ($i + 1);
            }
        }

        if (empty($foundIn)) {
            return $this->finalizeResponse("Mohon maaf, sepertinya dari daftar beasiswa di atas tidak tersedia jurusan **" . ucwords($major) . "**. Namun Anda bisa mencoba mencari beasiswa lain dengan mengetik 'beasiswa jurusan " . $major . "' secara global. 😊", $normalizedData);
        }

        $numbers = implode(', ', $foundIn);
        if (count($foundIn) > 1) {
            $lastComma = strrpos($numbers, ',');
            if ($lastComma !== false) {
                $numbers = substr_replace($numbers, ' dan', $lastComma, 1);
            }
        }

        return $this->finalizeResponse("Iya! Dari daftar beasiswa di atas, jurusan **" . ucwords($major) . "** tersedia di beasiswa nomor **" . $numbers . "**. \n\nSilakan pilih nomor beasiswa tersebut untuk melihat detail syarat, benefit, dan cara daftarnya ya! 😊", $normalizedData);
    }

    private function handleFundingValidation($fundingType, $normalizedData, $ref = null)
    {
        $ref = strtolower(trim($ref ?? ''));
        $target = strtolower($fundingType);
        
        $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $target);
        
        if (str_contains($target, 'full') || str_contains($target, 'penuh')) {
            $targetType = 'fully funded';
            $displayType = $isEnglishQuery ? 'Fully Funded' : 'Pendanaan Penuh (Full Gratis)';
        } else {
            $targetType = 'partially funded';
            $displayType = $isEnglishQuery ? 'Partially Funded' : 'Pendanaan Sebagian (Parsial)';
        }

        if (preg_match('/\b(?:no|nomor|#|ke)\s*([0-9]+)\b/i', $ref, $refMatches)) {
            $num = (int)$refMatches[1];
            $results = session()->get('last_search_all_results', []);
            if (isset($results[$num - 1])) {
                $s = (array)$results[$num - 1];
                $actual = strtolower($s['kategori'] ?? '');
                $nama = $s['nama_beasiswa'];
                
                if ($isEnglishQuery) {
                    $actualDisplay = 'Partially Funded';
                    if ((str_contains($actual, 'fully') || str_contains($actual, 'penuh')) && (str_contains($actual, 'partially') || str_contains($actual, 'sebagian') || str_contains($actual, 'partial'))) {
                        $actualDisplay = 'Fully & Partially Funded';
                    } elseif (str_contains($actual, 'fully') || str_contains($actual, 'penuh')) {
                        $actualDisplay = 'Fully Funded';
                    }
                } else {
                    $actualDisplay = 'Pendanaan Sebagian (Parsial)';
                    if ((str_contains($actual, 'fully') || str_contains($actual, 'penuh')) && (str_contains($actual, 'partially') || str_contains($actual, 'sebagian') || str_contains($actual, 'partial'))) {
                        $actualDisplay = 'Pendanaan Penuh & Sebagian';
                    } elseif (str_contains($actual, 'fully') || str_contains($actual, 'penuh')) {
                        $actualDisplay = 'Pendanaan Penuh (Full Gratis)';
                    }
                }

                if (str_contains($actual, $targetType) || ($targetType === 'fully funded' && str_contains($actual, 'penuh'))) {
                    return $this->finalizeResponse("Iya, beasiswa nomor $num (**$nama**) kategorinya adalah **$actualDisplay**. 😊", $normalizedData);
                } else {
                    return $this->finalizeResponse("Bukan, beasiswa nomor $num (**$nama**) kategorinya adalah **$actualDisplay**, bukan $displayType. 😊", $normalizedData);
                }
            }
        }

        if (session()->has('selected_scholarship') && ($ref === 'ini' || $ref === 'itu' || $ref === 'tersebut' || $ref === '')) {
            $s = session()->get('selected_scholarship');
            $actual = strtolower($s['kategori'] ?? '');
            $nama = $s['nama_beasiswa'];
            
            if ($isEnglishQuery) {
                $actualDisplay = 'Partially Funded';
                if ((str_contains($actual, 'fully') || str_contains($actual, 'penuh')) && (str_contains($actual, 'partially') || str_contains($actual, 'sebagian') || str_contains($actual, 'partial'))) {
                    $actualDisplay = 'Fully & Partially Funded';
                } elseif (str_contains($actual, 'fully') || str_contains($actual, 'penuh')) {
                    $actualDisplay = 'Fully Funded';
                }
            } else {
                $actualDisplay = 'Pendanaan Sebagian (Parsial)';
                if ((str_contains($actual, 'fully') || str_contains($actual, 'penuh')) && (str_contains($actual, 'partially') || str_contains($actual, 'sebagian') || str_contains($actual, 'partial'))) {
                    $actualDisplay = 'Pendanaan Penuh & Sebagian';
                } elseif (str_contains($actual, 'fully') || str_contains($actual, 'penuh')) {
                    $actualDisplay = 'Pendanaan Penuh (Full Gratis)';
                }
            }

            if (str_contains($actual, $targetType) || ($targetType === 'fully funded' && str_contains($actual, 'penuh'))) {
                return $this->finalizeResponse("Iya, beasiswa **$nama** ini kategorinya adalah **$actualDisplay**. 😊", $normalizedData);
            } else {
                return $this->finalizeResponse("Bukan, beasiswa **$nama** ini kategorinya adalah **$actualDisplay**. 😊", $normalizedData);
            }
        }
        
        return $this->finalizeResponse("Maaf, saya tidak yakin beasiswa mana yang Anda maksud untuk pertanyaan pendanaan tersebut. Silakan pilih nomor beasiswa terlebih dahulu.", $normalizedData);
    }

    private function getOutOfTopicResponse()
    {
        return "Mohon maaf, saat ini **ScholarBot** difokuskan untuk membantu Anda seputar informasi, pencarian, dan tata cara pendaftaran beasiswa saja. 😊\n\nUntuk pertanyaan di luar topik tersebut (seperti pengurusan visa pasca-kelulusan, resep masakan, cuaca, dll), saya belum bisa menjawabnya. \n*(Tips: Jika Anda ingin bertanya bebas mengenai topik umum, silakan nonaktifkan tombol **'RAG'** di atas untuk menggunakan mode Pure AI)*.";
    }

    private function getScholarshipContext($query)
    {
        $vStart = microtime(true);
        try {
            $embedding = $this->generateEmbedding($query);
            $results = DB::select("SELECT nama_beasiswa, negara, jenjang, bidang, deskripsi, persyaratan, benefit, deadline, url, url_asli 
                                FROM hybrid_search(?::text, ?::vector, ?::int) 
                                LIMIT 5", [$query, '[' . implode(',', $embedding) . ']', 5]);
            $this->vectorSearchTime += (microtime(true) - $vStart);
            
            $context = "";
            foreach ($results as $r) {
                $r = (array)$r;
                $context .= "Beasiswa: {$r['nama_beasiswa']}\nNegara: {$r['negara']}\nJenjang: {$r['jenjang']}\nDeadline: {$r['deadline']}\nDeskripsi: {$r['deskripsi']}\nBenefit: {$r['benefit']}\nSyarat: {$r['persyaratan']}\nLink Daftar: {$r['url']}\nLink Resmi: {$r['url_asli']}\n---\n";
            }
            return $context ?: "Tidak ada data spesifik di database yang cocok dengan pertanyaan ini.";
        } catch (\Exception $e) {
            Log::error("Context Retrieval Error: " . $e->getMessage());
            return "Gagal mengambil data dari database.";
        }
    }


    private function parseDeadlineDate($dateStr)
    {
        if (empty($dateStr) || $dateStr === '-' || strtolower(trim($dateStr)) === 'tutup') return null;
        
        $months = [
            'januari' => 'january', 'februari' => 'february', 'maret' => 'march',
            'april' => 'april', 'mei' => 'may', 'juni' => 'june',
            'juli' => 'july', 'agustus' => 'august', 'september' => 'september',
            'oktober' => 'october', 'november' => 'november', 'desember' => 'december',
            'pebruari' => 'february', 'febuari' => 'february', 'pebuari' => 'february',
            'nopember' => 'november', 'jan' => 'january', 'feb' => 'february',
            'mar' => 'march', 'apr' => 'april', 'jun' => 'june', 'jul' => 'july',
            'agu' => 'august', 'agt' => 'august', 'aug' => 'august', 'sep' => 'september',
            'okt' => 'october', 'oct' => 'october', 'nov' => 'november', 'des' => 'december',
            'dec' => 'december'
        ];

        $times = [];
        if (preg_match_all('/\b(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})\b/', $dateStr, $matches)) {
            foreach ($matches[0] as $matchDate) {
                $lower = strtolower(trim($matchDate));
                foreach ($months as $id => $en) {
                    $lower = preg_replace('/\b' . preg_quote($id, '/') . '\b/i', $en, $lower);
                }
                $t = strtotime($lower);
                if ($t !== false) {
                    $times[] = $t;
                }
            }
        }

        if (empty($times)) {
            $lower = strtolower(trim($dateStr));
            foreach ($months as $id => $en) {
                $lower = preg_replace('/\b' . preg_quote($id, '/') . '\b/i', $en, $lower);
            }
            $t = strtotime($lower);
            if ($t !== false) {
                $times[] = $t;
            }
        }

        if (empty($times)) return null;

        $now = time();
        $futureTimes = array_filter($times, fn($t) => $t >= $now);

        if (!empty($futureTimes)) {
            return min($futureTimes);
        } else {
            return max($times);
        }
    }
}
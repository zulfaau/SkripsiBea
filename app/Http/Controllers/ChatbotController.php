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

        // =================================================================
        // CARA ALTERNATIF: TRANSLATE WAKTU RELATIF MENJADI ABSOLUT
        // Kita ubah "bulan ini" jadi "bulan saat ini" sebelum ke AI
        // =================================================================
        $bulanIndo = [1 => 'januari', 2 => 'februari', 3 => 'maret', 4 => 'april', 5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'agustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'desember'];
        $bulanSekarang = $bulanIndo[(int)date('m')];
        $tahunSekarang = date('Y');

        $rawMessage = preg_replace('/\b(bulan\s+ini|bulan\s+sekarang)\b/i', 'bulan ' . $bulanSekarang, $rawMessage);
        $rawMessage = preg_replace('/\b(tahun\s+ini|tahun\s+sekarang)\b/i', 'tahun ' . $tahunSekarang, $rawMessage);
        $rawMessage = preg_replace('/\b(tahun\s+depan)\b/i', 'tahun ' . ($tahunSekarang + 1), $rawMessage);

        // JIKA RAG DIMATIKAN, LANGSUNG KE AI TANPA CEK DATABASE
        if (!$ragEnabled) {
            return $this->handlePureAI($rawMessage);
        }
        
        // Normalisasi ringan HANYA untuk fast-path regex. Koreksi typo/sinonim ditangani LLM.
        $msg = $this->lightNormalize($rawMessage);

        try {
            // =================================================================
            // FAST-PATH (regex murah, TANPA memanggil LLM) untuk input sepele.
            // =================================================================

            // Salam / terima kasih / konfirmasi
            if ($this->isGreeting($msg)) {
                $this->currentIntent = 'greeting';
                return $this->finalizeResponse($this->getGreetingResponse($msg));
            }
            if ($this->isThankYou($msg)) {
                $this->currentIntent = 'thank_you';
                return $this->finalizeResponse($this->getThankYouResponse());
            }
            if ($this->isAcknowledgment($msg)) {
                $this->currentIntent = 'acknowledgment';
                return $this->finalizeResponse("Baik, senang bisa membantu Anda! 😊 Jika nanti ada hal lain yang ingin ditanyakan seputar beasiswa, jangan ragu untuk kembali lagi ya. Semangat dan sukses untuk studinya! 🎓✨");
            }

            // Deteksi pemilihan nomor & intent detail (untuk "pilih no 3" / "benefit no 2" / "benefit")
            $detailIntent = $this->getDetailIntent($msg);
            $isExplicitSearch = preg_match('/\b(cari|carikan|berikan|tampilkan|temukan|info beasiswa|daftar beasiswa)\b/i', $msg);
            $selectedNumber = null;
            if (preg_match('/\b(?:nomor|no|pilih|nmr|#)\s*([0-9]+)\b/i', $msg, $mNum) ||
                preg_match('/^(?:pilih\s+|nomor\s+|no\s+|nmr\s+|#)?([0-9]+)$/i', trim($msg), $mNum)) {
                $selectedNumber = (int)$mNum[1];
            } elseif ($detailIntent && preg_match('/\b([0-9]{1,2})\b/', $msg, $mNum)) {
                $selectedNumber = (int)$mNum[1];
            }

            // Detail beasiswa yang sudah dipilih (mis. "benefit", "syarat", "cara daftar")
            if ($detailIntent && session()->has('selected_scholarship') && !$selectedNumber && !$isExplicitSearch) {
                $this->currentIntent = 'detail';
                return $this->handleDetailRequest($detailIntent, null);
            }

            // Detail diminta TAPI belum ada beasiswa yang dipilih, padahal list sedang aktif.
            // Jangan jatuh ke pencarian (yang malah men-dump ulang list); minta user pilih nomor dulu.
            if ($detailIntent && !session()->has('selected_scholarship') && session()->has('last_search_all_results')
                && !$selectedNumber && !$isExplicitSearch) {
                $this->currentIntent = 'detail';
                return $this->finalizeResponse("Silakan ketik **nomor** beasiswa dari daftar di atas terlebih dahulu ya untuk melihat detailnya 😊 (contoh: ketik **1**).");
            }

            // Eksekusi pemilihan nomor
            if ($selectedNumber) {
                $allResults = session()->get('last_search_all_results', []);
                if (isset($allResults[$selectedNumber - 1])) {
                    $selected = (array)$allResults[$selectedNumber - 1];
                    session()->put('selected_scholarship', $selected);
                    $this->currentIntent = 'detail';
                    // Jika user menyebut intent detail spesifik (mis. "benefit no 2") pakai itu;
                    // jika hanya nomor, LANGSUNG tampilkan ringkasan data (tanpa tanya lagi).
                    return $this->handleDetailRequest($detailIntent ?: 'detail', null);
                }
                return $this->finalizeResponse("Maaf, nomor tersebut tidak valid atau tidak ada dalam daftar pencarian terakhir Anda.");
            }

            // Paginasi ("yang lain", "selanjutnya", "ada lagi", dst)
            $isNextPage = preg_match('/\b(lainnya|yang\s+lain|selanjutnya|lain|berikutnya|next)\b/i', $msg) || preg_match('/\b(tampilkan\s+lagi|lagi\s+dong|ada\s+lagi|masih\s+ada|tampilkan\s+yang\s+lain)\b/i', $msg);
            if ($isNextPage && session()->has('last_search_all_results')) {
                $this->currentIntent = 'next_page';
                return $this->handleNextPage(null);
            }

            // "Kembali" -> tampilkan lagi list beasiswa sebelumnya
            if (preg_match('/^(kembali|balik|list( sebelumnya)?|daftar sebelumnya|beasiswa sebelumnya)$/i', trim($msg)) && session()->has('last_search_results')) {
                $this->currentIntent = 'back_to_list';
                return $this->showLastList();
            }

            // FAQ kurasi (LPDP/Erasmus/MEXT/definisi) - deterministik & murah
            $faqAnswer = $this->handleFAQ($msg);
            if ($faqAnswer) {
                $this->currentIntent = 'faq';
                return $this->finalizeResponse($faqAnswer);
            }

            // =================================================================
            // LAPISAN PEMAHAMAN LLM (untuk semua input bermakna lainnya).
            // Jika gagal → pesan error (TANPA fallback rule-based, sesuai keputusan).
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
            if ($intent === 'greeting') {
                $this->currentIntent = 'greeting';
                return $this->finalizeResponse($this->getGreetingResponse($msg));
            }
            if ($intent === 'thanks') {
                $this->currentIntent = 'thank_you';
                return $this->finalizeResponse($this->getThankYouResponse());
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
                    $resp = $this->handleValidationQuery($criteria, $target, $rawMessage);
                    if ($resp) return $resp;
                }
            }

            // Detail via LLM (mis. typo berat "bnefit" yang lolos fast-path)
            if ($intent === 'detail' && !empty($llm['detail_type'])) {
                if ($refNumber) {
                    $results = session()->get('last_search_all_results', []);
                    if (isset($results[$refNumber - 1])) session()->put('selected_scholarship', (array)$results[$refNumber - 1]);
                }
                if (session()->has('selected_scholarship')) {
                    $this->currentIntent = 'detail';
                    return $this->handleDetailRequest($llm['detail_type'], null);
                }
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
        // Simpan log ke database untuk evaluasi kinerja (Response Time & Accuracy)
        try {
            $duration = microtime(true) - $this->startTime;
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

        return response()->json([
            'success' => $success,
            'answer' => $answer
        ]);
    }

    private function isGreeting($message)
    {
        $greetingsRegex = '/\b(ha+i+|hi+|ha+lo+|ha+llo+|he+lo+|he+llo+|pagi+|siang+|sore+|malam+|permisi+|assalamualaikum+)\b/i';
        $intents = ['mau nanya', 'tanya dong', 'boleh tanya', 'nanya dong', 'saya mau tanya', 'boleh nanya', 'bisakah saya tanya', 'ada yang mau saya tanyakan', 'tanya ngga'];
        
        $isGreet = false;
        if (preg_match($greetingsRegex, $message)) {
            $isGreet = true;
        } else {
            foreach ($intents as $intent) {
                if (preg_match('/\b' . preg_quote($intent, '/') . '\b/i', $message)) {
                    $isGreet = true;
                    break;
                }
            }
        }

        // Khusus untuk "p" sebagai salam singkat, harus berdiri sendiri
        if (!$isGreet && preg_match('/^p$/i', trim($message))) {
            $isGreet = true;
        }

        if ($isGreet) {
            $searchKeywords = ['beasiswa', 'scholarship', 's1', 's2', 's3', 'negara', 'bulan', 'deadline', 'apply', 'benefit', 'syarat'];
            foreach ($searchKeywords as $kw) {
                if (str_contains($message, $kw)) return false; 
            }
            return true;
        }
        return false;
    }

    private function getGreetingResponse($message)
    {
        // Jika user minta izin bertanya
        if (str_contains($message, 'tanya') || str_contains($message, 'nanya')) {
            $responses = [
                "Tentu, silakan! Dengan senang hati saya akan membantu 😊 Apa yang ingin Anda tanyakan seputar beasiswa?",
                "Boleh banget! Apa nih yang ingin kamu tanyain seputar info beasiswa? Aku siap bantu jawab ya! 😊",
                "Silakan! **ScholarBot** siap membantu menjawab keraguan kamu seputar beasiswa. Mau tanya tentang apa nih? 🎓"
            ];
            return $responses[array_rand($responses)];
        }

        // Cek apakah ada sapaan waktu
        $timeGreeting = '';
        if (str_contains($message, 'pagi')) $timeGreeting = 'pagi';
        elseif (str_contains($message, 'siang')) $timeGreeting = 'siang';
        elseif (str_contains($message, 'sore')) $timeGreeting = 'sore';
        elseif (str_contains($message, 'malam')) $timeGreeting = 'malam';

        if ($timeGreeting) {
            $responses = [
                "Selamat " . $timeGreeting . " juga! 😊 Ada yang bisa saya bantu terkait informasi beasiswa?",
                "Halo, selamat " . $timeGreeting . "! 👋 Ada yang ingin Anda tanyakan seputar beasiswa hari ini?",
                "Selamat " . $timeGreeting . "! 😊 Kabar baik hari ini? Ada yang bisa saya bantu untuk mencari beasiswa?",
                "Halo! Selamat " . $timeGreeting . " juga. Ada hal yang bisa saya bantu mengenai informasi beasiswa?",
                "Hai, selamat " . $timeGreeting . "! Semangat terus ya cari beasiswanya. Ada yang mau ditanyakan?",
                "Selamat " . $timeGreeting . "! Senang sekali bisa membantu Anda hari ini. Mau cari beasiswa di negara mana nih? 🎓"
            ];
        } else {
            // Jika user sekadar menyapa umum (Halo/Hi/Assalamualaikum)
            $responses = [
                "Halo! 😊 Ada yang bisa dibantu mengenai informasi beasiswa?",
                "Halo! 👋 Ada yang bisa saya bantu terkait informasi beasiswa hari ini?",
                "Hi! 😊 Ada yang bisa saya bantu untuk mencari beasiswa yang sesuai dengan Anda?",
                "Halo! Ada yang bisa saya bantu mengenai informasi beasiswa atau studi luar negeri?",
                "Halo, pejuang beasiswa! 👋 Apa yang bisa saya bantu hari ini?",
                "Hai! **ScholarBot** di sini siap membantu kamu cari info beasiswa terbaik. Ada yang ingin ditanyakan? 😊"
            ];
        }

        return $responses[array_rand($responses)];
    }

    private function isAcknowledgment($message)
    {
        $acks = ['oke', 'okey', 'siap', 'sip', 'iya', 'baik', 'baiklah', 'ok', 'okei', 'paham', 'mengerti', 'yoi', 'yup', 'mantap'];
        $isAck = false;
        foreach ($acks as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                $isAck = true;
                break;
            }
        }
        
        if ($isAck) {
            // Jika pesan mengandung sinyal pencarian/beasiswa atau cukup panjang, jangan anggap
            // sebagai acknowledgment saja (biar lanjut ke LLM understand).
            if (strlen($message) > 15 || preg_match('/\b(beasiswa|scholarship|cari|carikan|s1|s2|s3|jurusan|negara|deadline|benefit|syarat)\b/i', $message)) {
                return false;
            }
            return true;
        }
        return false;
    }

    private function isThankYou($message)
    {
        $thanks = ['terima kasih', 'terimakasih', 'makasih', 'suwun', 'thanks', 'thx', 'thank you', 'tengkyu', 'mksh', 'maturnuwun', 'tks'];
        $isThanks = false;
        foreach ($thanks as $word) {
            if (str_contains($message, $word)) { $isThanks = true; break; }
        }
        if (!$isThanks) return false;

        // Jika kalimat juga mengandung sinyal pencarian beasiswa, ucapan terima kasih
        // hanya pelengkap (mis. "carikan beasiswa s2 ... makasih") -> bukan intent thank-you.
        if (preg_match('/\b(beasiswa|scholarship|cari|carikan|rekomendasi|s1|s2|s3|magister|sarjana|doktor|jurusan|fully|funded|luar\s*negeri|dalam\s*negeri)\b/i', $message)) {
            return false;
        }
        return true;
    }

    private function getThankYouResponse()
    {
        $responses = [
            "Sama-sama! Senang bisa membantu Anda 😊 Jika ada hal lain yang ingin ditanyakan seputar beasiswa, jangan ragu untuk bertanya ya!",
            "Terima kasih kembali! Semoga sukses dengan pendaftaran beasiswanya 🎓✨",
            "Sama-sama! Semangat terus pejuang beasiswa! 💪 Ada lagi yang bisa saya bantu?",
            "Anytime! Senang bisa menemani pencarian beasiswa Anda hari ini. Sukses terus ya! 😊"
        ];
        return $responses[array_rand($responses)];
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

    private function handleFAQ($message)
    {
        $m = strtolower($message);

        // Hanya trigger FAQ statis jika bertanya "apakah ada" atau "adakah"
        if (preg_match('/\b(apakah ada|adakah)\b/i', $m) && preg_match('/\b(tanpa toefl|tanpa ielts|tanpa syarat|tanpa persyaratan)\b/i', $m)) {
            return "Ada beberapa beasiswa yang tidak mewajibkan TOEFL/IELTS, seperti beasiswa Turkiye Burslari (Turki), GKS (Korea - jalur tertentu), atau beasiswa Pemerintah Rusia. Beberapa beasiswa dalam negeri juga banyak yang tidak memerlukan sertifikat bahasa Inggris.";
        }

        // List pertanyaan titipan user (Possibility)
        if (str_contains($m, 'lpdp')) {
            if (str_contains($m, 'cara')) {
                return "Cara mendaftar beasiswa LPDP secara umum meliputi registrasi online di situs resmi LPDP, mengisi formulir pendaftaran, mengunggah berkas syarat (seperti LoA, TOEFL/IELTS, surat rekomendasi, esai), dan mengikuti seleksi administrasi, bakat skolastik, serta wawancara. 😊";
            }
            return "Beasiswa LPDP biasanya dibuka dalam 2 tahap setiap tahunnya (sekitar bulan Januari-Februari untuk Tahap 1 dan Juni-Juli untuk Tahap 2). Untuk update resmi tahun 2026, silakan pantau terus situs lpdp.kemenkeu.go.id ya! 😊";
        }
        if (str_contains($m, 'erasmus')) {
            return "Syarat utama beasiswa Erasmus+ (EMJM) biasanya meliputi memiliki gelar sarjana (S1), sertifikat kemampuan bahasa Inggris (IELTS/TOEFL), surat rekomendasi, CV, Motivation Letter, dan mendaftar pada program konsorsium Erasmus yang dituju. 😊";
        }
        if (str_contains($m, 'mext')) {
            return "Beasiswa MEXT (Monbukagakusho) dari Pemerintah Jepang adalah beasiswa fully funded yang menanggung penuh biaya kuliah, tunjangan hidup bulanan, serta tiket pesawat pergi-pulang. 😊";
        }
        if (str_contains($m, 'wawancara') && (str_contains($m, 'tidak pakai') || str_contains($m, 'tanpa'))) {
            return "Beasiswa tanpa wawancara biasanya fokus pada seleksi berkas dan nilai akademik. Contohnya beberapa beasiswa bantuan UKT atau beasiswa dari yayasan swasta tertentu. Namun mayoritas beasiswa bergengsi biasanya tetap menyertakan tahap wawancara.";
        }
        if (str_contains($m, 'fresh graduate') || str_contains($m, 'pengalaman')) {
            return "Tentu! Banyak beasiswa S2 luar negeri yang sangat terbuka untuk fresh graduate tanpa syarat pengalaman kerja, seperti beasiswa Erasmus+ (Eropa), MEXT (Jepang), atau beasiswa dari universitas di Taiwan.";
        }
        if (str_contains($m, 'kurang mampu') || str_contains($m, 'anak mampu') || str_contains($m, 'kip')) {
            return "Untuk mahasiswa kurang mampu, pilihan utamanya adalah KIP Kuliah (untuk dalam negeri) atau beasiswa yang berbasis 'Need-based Financial Aid' untuk luar negeri. Kami memiliki beberapa data bantuan kuliah tersebut di database.";
        }

        if (str_contains($m, 'perbedaan') && (str_contains($m, 'penuh') || str_contains($m, 'fully') || str_contains($m, 'full')) && (str_contains($m, 'sebagian') || str_contains($m, 'partially') || str_contains($m, 'partial'))) {
            return "Perbedaan utamanya:\n- **Beasiswa Penuh (Fully Funded)** menanggung seluruh biaya (kuliah, hidup, tiket, dll).\n- **Beasiswa Sebagian (Partially Funded)** hanya menanggung sebagian biaya (misal hanya biaya kuliah atau uang saku saja).";
        }
        if (str_contains($m, 'perbedaan') && str_contains($m, 'fully funded') && str_contains($m, 'partially funded')) {
            return "Perbedaan utamanya:\n- **Fully Funded** menanggung seluruh biaya (kuliah, hidup, tiket, dll).\n- **Partially Funded** hanya menanggung sebagian biaya (misal hanya biaya kuliah atau uang saku saja).";
        }
        
        // Deteksi Pengertian (Hanya jika 'apa itu' secara spesifik)
        if (preg_match('/\b(apa itu|pengertian|maksud dari|definisi|jelaskan|maksud|dimaksud|arti)\b/i', $m)) {
            if (str_contains($m, 'fully funded') || str_contains($m, 'full funded') || str_contains($m, 'penuh')) {
                return "Fully Funded adalah jenis beasiswa yang menanggung seluruh biaya studi, biasanya mencakup biaya kuliah (tuition fee), biaya hidup (living allowance), asuransi kesehatan, hingga tiket pesawat.";
            }
            if (str_contains($m, 'partially funded') || str_contains($m, 'partial funded') || str_contains($m, 'sebagian')) {
                return "Partially Funded adalah beasiswa yang hanya menanggung sebagian biaya studi, misalnya hanya membiayai uang kuliah saja (tuition only) tanpa biaya hidup, atau sebaliknya.";
            }
        }

        // Hanya trigger jika user secara spesifik menanyakan pengertiannya
        if (preg_match('/\b(apa itu|pengertian|definisi|maksud|arti|jelaskan)\b/i', $m) && (str_contains($m, 'ielts') || str_contains($m, 'toefl'))) {
            return "IELTS (International English Language Testing System) dan TOEFL (Test of English as a Foreign Language) adalah tes standar internasional untuk mengukur kemampuan bahasa Inggris yang sering menjadi syarat utama pendaftaran beasiswa luar negeri.";
        }
        if (str_contains($m, 'loa')) {
            return "LoA (Letter of Acceptance) adalah surat resmi dari universitas yang menyatakan bahwa Anda telah diterima sebagai mahasiswa di universitas tersebut. LoA sering menjadi salah satu syarat mendaftar beasiswa.";
        }
        return null;
    }

    private function getDetailIntent($m)
    {
        $m = strtolower($m);
        
        if (preg_match('/\b(url|link|tautan|web|website)\b/i', $m)) return 'url';
        if (preg_match('/\b(daftar|mendaftar|mendaftarkan|pendaftaran|apply|registrasi|gabung|join)\b/i', $m)) return 'apply';
        if (preg_match('/\b(benefit|tunjangan|fasilitas|dana|biaya|funding|didapat|di dapat|dapatnya|dapetnya|cakupan|ditanggung|dibiayai|cover|uang saku|akomodasi)\b/i', $m)) {
            if (preg_match('/\b(dana|biaya|funding)\b/i', $m)) return 'funding';
            return 'benefit';
        }
        if (preg_match('/\b(syarat|persyaratan|kualifikasi|kriteria|dokumen|berkas|eligibility|qualification|ketentuan)\b/i', $m)) return 'persyaratan';
        if (preg_match('/\b(deadline|dl|batas|tutup)\b/i', $m)) return 'deadline';
        if (preg_match('/\b(detail|info|lengkap|ringkasan)\b/i', $m)) return 'detail';

        return null;
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
                $translated = $this->translateToIndonesian($content);
                $ans = "Persyaratan **$name**:\n" . $translated; 
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

    private function showLastList()
    {
        $results = session()->get('last_search_results', []);
        if (empty($results)) {
            return $this->finalizeResponse("Maaf, belum ada daftar beasiswa sebelumnya. Silakan lakukan pencarian terlebih dahulu ya. 😊");
        }
        $page = session()->get('last_search_page', 1);
        $startIndex = ($page - 1) * 5;
        session()->forget('selected_scholarship');

        $resp = "Berikut kembali daftar beasiswa sebelumnya:\n\n";
        foreach ($results as $i => $s) {
            $s = (array)$s;
            $num = $startIndex + $i + 1;
            $resp .= $num . ". **" . trim($s['nama_beasiswa']) . "** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ") - Deadline: " . ($s['deadline'] ?? '-') . "\n\n";
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

        $resp = "Berikut daftar beasiswa selanjutnya:\n\n";
        foreach ($limitedResults as $i => $s) {
            $s = (array) $s;
            $namaBeasiswa = trim($s['nama_beasiswa']);
            $displayNumber = $startIndex + $i + 1;
            $resp .= $displayNumber . "\. **{$namaBeasiswa}** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ") - Deadline: " . ($s['deadline'] ?? '-') . "\n\n";
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
        // DETEKSI INTENT WAKTU (rule-based, deterministik, tak bergantung LLM).
        // Dihitung di AWAL agar query "masih buka / rentang waktu" tidak terbajak
        // ke jalur daftar-acak-per-tahun di bawah (yang mengabaikan filter waktu).
        // =================================================================
        if (preg_match('/\b(paling dekat|deadline dekat|mepet|terdekat|tercepat)\b/i', $message)) {
            $criteria['sort_deadline'] = true;
            $criteria['sort_deadline_dir'] = 'asc';
        } elseif (preg_match('/\b(terjauh|terlama|paling lama|paling jauh)\b/i', $message)) {
            $criteria['sort_deadline'] = true;
            $criteria['sort_deadline_dir'] = 'desc';
        }
        // "masih buka / aktif / belum tutup" -> still_open (tak hilang walau LLM lupa set).
        if (preg_match('/\b(masih\s+buka|masih\s+di\s?buka|sedang\s+di\s?buka|belum\s+(?:tutup|di\s?tutup|lewat|berakhir)|masih\s+aktif|masih\s+terbuka)\b/i', $message)) {
            $criteria['still_open'] = true;
        }
        // Batas ATAS waktu ("sampai akhir tahun", "sampai bulan maret"). Rule-based diutamakan;
        // jika tak cocok, nilai deadline_before dari LLM (mapLlmToCriteria) tetap dipakai.
        $upperBound = $this->parseDeadlineUpperBound($message);
        if ($upperBound !== null) {
            $criteria['deadline_before'] = $upperBound;
        }
        $hasTimeRangeIntent = !empty($criteria['still_open']) || !empty($criteria['deadline_before']) || !empty($criteria['sort_deadline']);

        $hasYearPattern = preg_match('/\b(20[2-3][0-9])\b/', $message, $matches);
        if ($hasYearPattern) {
            $year = $matches[1];
            $isGeneralListRequest = preg_match('/\b(data|list|daftar|semua|tampilkan|berikan|print|show|kumpulan|database|seluruh)\b/i', $message);
            
            $tempCriteria = $criteria;
            $hasSpecificFilters = !empty($tempCriteria['negara']) || !empty($tempCriteria['benua']) || !empty($tempCriteria['jenjang']) || !empty($tempCriteria['bidang']) || !empty($tempCriteria['lokasi_tipe']) || !empty($tempCriteria['funding']);
            
            if (($isGeneralListRequest || !$hasSpecificFilters) && !$hasTimeRangeIntent) {
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
                    $s = (array)$s;
                    $resp .= ($i + 1) . ". **{$s['nama_beasiswa']}** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ") - Deadline: " . ($s['deadline'] ?? '-') . "\n\n";
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

        // CONTEXT MERGING & RESET
        if (session()->has('last_search_criteria')) {
            $lastCriteria = session()->get('last_search_criteria');
            
            $isExplicitNewSearch = preg_match('/\b(cari|carikan|nyari|temukan|mencari|tampilkan|berikan|list|semua)\b/i', $message) 
                || (str_contains($message, 'beasiswa') && strlen($message) > 25);

            $hasNewStrongCriteria = !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['bidang']) || !empty($criteria['tahun']) || !empty($criteria['lokasi_tipe']) || $isExplicitNewSearch;
            
            if (!$hasNewStrongCriteria) {
                if (empty($criteria['negara']) && !empty($lastCriteria['negara'])) $criteria['negara'] = $lastCriteria['negara'];
                if (empty($criteria['benua']) && !empty($lastCriteria['benua'])) $criteria['benua'] = $lastCriteria['benua'];
                if (empty($criteria['lokasi_tipe']) && !empty($lastCriteria['lokasi_tipe'])) $criteria['lokasi_tipe'] = $lastCriteria['lokasi_tipe'];
                if (empty($criteria['jenjang']) && !empty($lastCriteria['jenjang'])) $criteria['jenjang'] = $lastCriteria['jenjang'];
                if (empty($criteria['bidang']) && !empty($lastCriteria['bidang'])) $criteria['bidang'] = $lastCriteria['bidang'];
                if (empty($criteria['funding']) && !empty($lastCriteria['funding'])) $criteria['funding'] = $lastCriteria['funding'];
                // Pertahankan filter waktu agar re-run tidak memunculkan beasiswa kedaluwarsa / di luar rentang.
                if (empty($criteria['still_open']) && !empty($lastCriteria['still_open'])) $criteria['still_open'] = $lastCriteria['still_open'];
                if (empty($criteria['deadline_before']) && !empty($lastCriteria['deadline_before'])) $criteria['deadline_before'] = $lastCriteria['deadline_before'];
                if (empty($criteria['sort_deadline']) && !empty($lastCriteria['sort_deadline'])) {
                    $criteria['sort_deadline'] = $lastCriteria['sort_deadline'];
                    $criteria['sort_deadline_dir'] = $lastCriteria['sort_deadline_dir'] ?? 'asc';
                }
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

        if (empty($filtered)) {
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
            
            $hasDeadlineKeyword = preg_match('/\b(deadline|dl|tanggal|bulan|kapan|tutup|batas|buka|aktif|sekarang)\b/i', $message) 
                || !empty($criteria['bulan']) || !empty($criteria['sort_deadline']) || !empty($criteria['still_open']);
            
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
                    } elseif (str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial')) {
                        $kategoriDisplay = "Partially Funded";
                    } elseif (!empty($s['kategori'])) {
                        $kategoriDisplay = ucwords($s['kategori']);
                    }
                } else {
                    $kategoriDisplay = "Pendanaan Sebagian (Parsial)";
                    if ((str_contains($kat, 'fully') || str_contains($kat, 'penuh')) && (str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial'))) {
                        $kategoriDisplay = "Pendanaan Penuh & Sebagian";
                    } elseif (str_contains($kat, 'fully') || str_contains($kat, 'penuh')) {
                        $kategoriDisplay = "Pendanaan Penuh (Full Gratis)";
                    } elseif (str_contains($kat, 'partially') || str_contains($kat, 'sebagian') || str_contains($kat, 'partial')) {
                        $kategoriDisplay = "Pendanaan Sebagian (Parsial)";
                    } elseif (!empty($s['kategori'])) {
                        $kategoriDisplay = ucwords($s['kategori']);
                    }
                }
                $attrs[] = $kategoriDisplay;
            }

            $resp .= ($i + 1) . ". **{$namaBeasiswa}**";
            if (!empty($attrs)) {
                $resp .= " (" . implode(' - ', $attrs) . ")";
            }
            $resp .= " - Deadline: " . ($s['deadline'] ?? '-');
            $resp .= "\n\n";
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
            
            if (!empty($criteria['gender']) && $criteria['gender'] === 'perempuan' && !str_contains($content, 'perempuan') && !str_contains($content, 'wanita')) return false;
            if (!empty($criteria['ekonomi']) && !str_contains($content, 'kurang mampu') && !str_contains($content, 'ekonomi') && !str_contains($content, 'kip')) return false;
            if (!empty($criteria['fresh_grad']) && !str_contains($content, 'fresh graduate') && !str_contains($content, 'lulusan baru')) return false;
            if (!empty($criteria['no_interview']) && str_contains($content, 'wawancara')) {
                if (!str_contains($content, 'tanpa wawancara')) return false;
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
                        if (str_contains($rowBidang, $b) || str_contains($rowDeskripsi, $b) || str_contains($rowPersyaratan, $b)) {
                            $m = true;
                            break;
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

    private function lightNormalize($text)
    {
        $text = preg_replace('/[?!.,\/#$%\^&\*;:{}=_`~()]/', ' ', strtolower($text));
        return preg_replace('/\s+/', ' ', trim($text));
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
(PENTING: Jika user menyebut kata "bulan ini", "tahun ini", atau "sekarang", Anda WAJIB menerjemahkannya menjadi bulan dan tahun di atas ke dalam output JSON).

Toleransi typo, singkatan (s2=S2, ln=luar negeri, dn=dalam negeri, dll), bahasa gaul, dan bahasa Inggris. Pahami maksud sebenarnya.

KONTEKS PERCAKAPAN SAAT INI:
$sessionContext

Keluarkan HANYA JSON valid dengan skema:
{
  "intent": "search | detail | validation | next_page | back_to_list | out_of_topic | greeting | thanks",
  "detail_type": "benefit | syarat | deadline | funding | url | apply | detail | null",
  "ref_number": <int atau null>,
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
  "exclude": { "negara": [], "benua": [], "jenjang": [], "bidang": [], "funding": null }
}

ATURAN PENTING:
- intent "search": user mencari/minta daftar beasiswa dengan kriteria apa pun.
- intent "detail": user menanyakan benefit/syarat/deadline/cara daftar/link dari beasiswa yang SUDAH dipilih (lihat konteks). Isi detail_type.
- intent "validation": user bertanya YA/TIDAK tentang beasiswa yang sedang dipilih/dirujuk (mis. "apakah ini di jepang?", "ada jurusan kedokteran ga?"). Isi kriteria yang divalidasi.
- intent "next_page": user minta MELANJUTKAN daftar hasil sebelumnya / melihat lebih banyak (mis. "yang lain", "selanjutnya", "berikutnya", "ada lagi", "tampilkan lagi", "lainnya"). WAJIB toleran typo: "yang laib", "slanjutnya", "lainnyaa", "ada lg" -> tetap next_page. JANGAN isi kriteria baru.
- intent "back_to_list": user minta KEMBALI ke daftar beasiswa sebelumnya (mis. "kembali", "balik", "list sebelumnya", "daftar tadi"). Toleran typo. JANGAN isi kriteria baru.
- intent "out_of_topic": pesan TIDAK masuk akal sebagai pencarian beasiswa atau di luar topik beasiswa/pendidikan. Contoh: "beasiswa warnanya apa" (beasiswa tak punya warna), "resep nasi goreng", "cuaca hari ini". Walau ada kata "beasiswa", jika pertanyaannya nonsense -> out_of_topic.
- intent "greeting"/"thanks": sapaan / ucapan terima kasih murni.
- NEGARA: masukkan SEMUA nama tempat/negara yang user sebut ke "negara" (huruf kecil), TERMASUK yang tidak umum atau fiktif (mis. "wakanda", "atlantis", "antartika"), supaya ketersediaannya bisa divalidasi. "benua" HANYA boleh berisi: eropa, asia, amerika, afrika, australia; tempat lain masukkan ke "negara".
- NEGASI: "selain/bukan/kecuali/tanpa negara X" -> masukkan ke "exclude", JANGAN ke kriteria utama.
- "fully funded/gratis/pendanaan penuh/biaya penuh" -> funding "Fully Funded". "partially/sebagian/parsial" -> "Partially Funded".
- "exchange/pertukaran pelajar/student exchange/program pertukaran/exchange program" -> funding "Exchange".
- "deadline terdekat/paling dekat/segera tutup" -> sort_deadline "asc". "masih buka/belum lewat/aktif/sedang dibuka" -> still_open true.
- WAKTU RENTANG: Jika user meminta rentang waktu (misal: "sampai akhir tahun", "sampai bulan maret", "beberapa bulan ke depan"), KOSONGKAN array "bulan", set "still_open": true, DAN isi "deadline_before" dengan tanggal akhir rentang format YYYY-MM-DD (contoh: "sampai akhir tahun" -> "$tahunSekarang-12-31", "sampai bulan maret" -> "$tahunSekarang-03-31").
- Hanya isi tahun "2024".."2027". Kosongkan array jika tidak disebut.
- Keluarkan JSON saja, tanpa penjelasan, tanpa markdown.
PROMPT;

        $raw = $this->callChatLLM($system, $message, true, 0.0);
        $raw = trim($raw);
        $raw = preg_replace('/```(?:json)?/i', '', $raw);
        $raw = trim(str_replace('```', '', $raw));
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['intent'])) {
            throw new \Exception("Ekstraksi LLM tidak valid: " . substr($raw, 0, 200));
        }
        return $json;
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
                $systemPrompt .= "\n\nINSTRUKSI PENTING: Jawablah secara singkat dan akurat hanya berdasarkan context di atas. Jika tidak ada di context, katakan secara jujur bahwa informasi tersebut belum tersedia di database kami. Batasi daftar beasiswa maksimal 5 saja.";
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
        $responses = [
            "Mohon maaf, chatbot kami tidak menerima pertanyaan diluar informasi beasiswa, jika ingin bertanya hal tersebut bisa anda off kan toggle diatas dan silahkan ulangi pertanyaannya.",
            "Mohon maaf, saat ini chatbot ini hanya memberikan informasi seputar beasiswa. Jika ingin bertanya di luar topik tersebut, silakan nonaktifkan toggle RAG di atas ya. Terima kasih! 🙏",
            "Mohon maaf sekali, pertanyaan Anda di luar topik beasiswa. **ScholarBot** fokus pada bantuan informasi mengenai beasiswa"
        ];
        return $responses[array_rand($responses)];
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

    /**
     * Mendeteksi batas ATAS waktu dari frasa rentang user dan mengembalikannya sebagai
     * unix timestamp (akhir hari, inklusif). Mengembalikan null jika tak ada frasa yang cocok.
     * Contoh: "sampai akhir tahun" -> 31 Des tahun ini; "sampai bulan maret" -> 31 Mar tahun ini.
     */
    private function parseDeadlineUpperBound($message)
    {
        $msg = strtolower($message);
        $yearNow = (int)date('Y');

        // "(sampai/hingga) akhir tahun [YYYY]" -> 31 Desember.
        if (preg_match('/\bakhir\s+tahun(?:\s+(20[2-3][0-9]))?\b/i', $msg, $m)) {
            $yr = !empty($m[1]) ? (int)$m[1] : $yearNow;
            return mktime(23, 59, 59, 12, 31, $yr);
        }

        // "sampai/hingga/sebelum [akhir] [bulan] <namabulan> [YYYY]" -> akhir bulan tersebut.
        $bulanMap = [
            'januari' => 1, 'februari' => 2, 'pebruari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'september' => 9,
            'oktober' => 10, 'november' => 11, 'nopember' => 11, 'desember' => 12,
        ];
        $names = implode('|', array_keys($bulanMap));
        if (preg_match('/\b(?:sampai|hingga|sebelum|s\.?d\.?)\s+(?:akhir\s+)?(?:bulan\s+)?(' . $names . ')(?:\s+(20[2-3][0-9]))?\b/i', $msg, $m)) {
            $bln = $bulanMap[strtolower($m[1])];
            $yr = !empty($m[2]) ? (int)$m[2] : $yearNow;
            $lastDay = (int)date('t', mktime(0, 0, 0, $bln, 1, $yr));
            return mktime(23, 59, 59, $bln, $lastDay, $yr);
        }

        return null;
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
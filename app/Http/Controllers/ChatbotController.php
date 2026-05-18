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
     * Endpoint utama untuk chatbot (Rule-Based + AI Fallback)
     */
    public function ask(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500',
            'rag_enabled' => 'nullable|boolean',
        ]);

        $this->startTime = microtime(true);
        $this->userMessage = $request->input('message');

        $ragEnabled = $request->input('rag_enabled', true);
        $rawMessage = $request->input('message');

        // JIKA RAG DIMATIKAN, LANGSUNG KE AI TANPA CEK DATABASE
        if (!$ragEnabled) {
            return $this->handlePureAI($rawMessage);
        }
        $normalizedData = $this->normalizeText($rawMessage);
        $message = $normalizedData['text'];

        // PAKSA DETEKSI LOKASI (Karena filter sering bocor)
        $forcedLocation = null;
        if (preg_match('/\b(dalam negeri|indo|domestik|nasional)\b/i', $rawMessage)) $forcedLocation = 'dalam';
        if (preg_match('/\b(luar negeri|internasional|global|abroad)\b/i', $rawMessage)) $forcedLocation = 'luar';

        try {
            // TYPO SEKARANG LANGSUNG DIPROSES (TIDAK BERTANYA LAGI)
            // Sistem akan menggunakan pesan yang sudah dikoreksi di $message
            

            // 1. GREETING DETECTION
            if ($this->isGreeting($message)) {
                $this->currentIntent = 'greeting';
                return $this->finalizeResponse($this->getGreetingResponse($message), $normalizedData);
            }

            // 1.2 OUT OF TOPIC DETECTION (Sangat Prioritas - Agar tidak menjawab materi kuliah)
            if ($ragEnabled && $this->isOutOfTopic($message)) {
                $this->currentIntent = 'out_of_topic';
                return $this->finalizeResponse($this->getOutOfTopicResponse(), $normalizedData);
            }

            // 1.5 THANK YOU DETECTION
            if ($this->isThankYou($message)) {
                $this->currentIntent = 'thank_you';
                return $this->finalizeResponse($this->getThankYouResponse(), $normalizedData);
            }

            // 1.7 INTENT DETECTION (Deteksi niat user)
            $detailIntent = $this->getDetailIntent($message);

            // 1.7.5 VALIDASI JURUSAN (Konteks Follow-up: Apakah beasiswa ini ada jurusan X?)
            if ($ragEnabled && (session()->has('selected_scholarship') || session()->has('last_search_results'))) {
                // Pola: "beasiswa ini ada jurusan X?", "ada jurusan Y?", "jurusan X ada di beasiswa tersebut?"
                $majorRegex = '/\b(jurusan|prodi|bidang|fakultas|studi)\s+([a-z\s]+).*?\b(ini|itu|tersebut|no\s+\d+|nomor\s+\d+|ke\s+\d+)\b|\b(ini|itu|tersebut|no\s+\d+|nomor\s+\d+|ke\s+\d+).*?\b(jurusan|prodi|bidang|fakultas|studi)\s+([a-z\s]+)\b/i';
                if (preg_match($majorRegex, $message, $matches)) {
                    $ref = trim($matches[3] ?: $matches[4]);
                    $majorFound = trim($matches[2] ?: $matches[6]);
                    $this->currentIntent = 'validation_major';
                    return $this->handleMajorValidation($majorFound, $normalizedData, $ref);
                }
            }

            // 1.7.6 VALIDASI PENDANAAN (Konteks Follow-up: Apakah ini fully funded?)
            if ($ragEnabled && (session()->has('selected_scholarship') || session()->has('last_search_results'))) {
                // Pola: "fully funded kah beasiswa ini?", "apakah ini fully funded?"
                $fundingTypes = '(fully\s+funded|full\s+funded|partially\s+funded|partial\s+funded|dana\s+penuh|dana\s+sebagian|biaya\s+penuh|biaya\s+sebagian)';
                $refs = '(ini|itu|tersebut|no\s+\d+|nomor\s+\d+|ke\s+\d+)';
                $fundingRegex = "/\b$fundingTypes.*?\b$refs\b|\b$refs.*?\b$fundingTypes\b/i";
                
                if (preg_match($fundingRegex, $message, $matches)) {
                    // Cari mana yang merupakan rujukan dan mana yang merupakan tipe pendanaan
                    $ref = "";
                    $fundingFound = "";
                    
                    if (preg_match("/\b$refs\b/i", $message, $mRef)) $ref = $mRef[0];
                    if (preg_match("/\b$fundingTypes\b/i", $message, $mFund)) $fundingFound = $mFund[0];

                    $this->currentIntent = 'validation_funding';
                    return $this->handleFundingValidation($fundingFound, $normalizedData, $ref);
                }
            }

            // 2. DETAIL & SELECTION LOGIC (Prioritas Tinggi)
            $detailIntent = $this->getDetailIntent($message);
            $isExplicitSearch = preg_match('/\b(cari|carikan|berikan|tampilkan|temukan|info beasiswa|daftar beasiswa)\b/i', $message);

            // 2.1 Deteksi Pemilihan Nomor (Misal: "pilih no 1" atau "benefit no 2")
            $selectedNumber = null;
            if (preg_match('/\b(?:nomor|no|pilih|nmr|#)\s*([0-9]+)\b/i', $message, $matches) || 
                preg_match('/^(?:pilih\s+|nomor\s+|no\s+|nmr\s+|#)?([0-9]+)$/i', trim($message), $matches)) {
                $selectedNumber = (int)$matches[1];
            } else if ($detailIntent && preg_match('/\b([0-9]{1,2})\b/', $message, $matches)) {
                $selectedNumber = (int)$matches[1];
            }

            // 2.2 Jika ada intent detail dan sudah ada beasiswa terpilih
            // Jika ada intent detail, TAPI kalimatnya juga merupakan kalimat pencarian spesifik, maka prioritas ke pencarian
            if ($detailIntent && session()->has('selected_scholarship') && !$selectedNumber && !($isExplicitSearch && $this->isSearchQuery($message))) {
                $this->currentIntent = 'detail';
                return $this->handleDetailRequest($detailIntent, $normalizedData);
            }

            // 2.3 Eksekusi Pemilihan Nomor
            if ($selectedNumber) {
                $allResults = session()->get('last_search_all_results', []);
                if (isset($allResults[$selectedNumber - 1])) {
                    $selected = (array)$allResults[$selectedNumber - 1];
                    session()->put('selected_scholarship', $selected);

                    // Jika ada intent detail sekaligus (misal "benefit no 1")
                    if ($detailIntent) {
                        $this->currentIntent = 'detail';
                        return $this->handleDetailRequest($detailIntent, $normalizedData);
                    }
                    $this->currentIntent = 'selection';
                    return $this->finalizeResponse("Anda telah memilih **{$selected['nama_beasiswa']}**.\n\nDetail apa yang ingin Anda ketahui? 👉 Ketik: **Benefit, Syarat, Deadline, Cara Daftar**, atau **Detail**", $normalizedData);
                } else {
                    return $this->finalizeResponse("Maaf, nomor tersebut tidak valid atau tidak ada dalam daftar pencarian terakhir Anda.", $normalizedData);
                }
            }
            
            // 3.5 KONFIRMASI (Acknowledgment: Oke/Iya/Siap)
            if ($this->isAcknowledgment($message)) {
                $this->currentIntent = 'acknowledgment';
                return $this->finalizeResponse("Baik, senang bisa membantu Anda! 😊 Jika nanti ada hal lain yang ingin ditanyakan seputar beasiswa, jangan ragu untuk kembali lagi ya. Semangat dan sukses untuk studinya! 🎓✨", $normalizedData);
            }

            // 4.5 NEXT PAGE (YANG LAIN)
            $isNextPage = preg_match('/\b(lainnya|yang lain|selanjutnya|berikutnya|lagi|next)\b/i', $message);
            if ($isNextPage && session()->has('last_search_all_results')) {
                $criteria = $this->extractCriteria($message);
                $lastCriteria = session()->get('last_search_criteria', []);
                $isSameCountry = empty($criteria['negara']) || $criteria['negara'] === ($lastCriteria['negara'] ?? []);
                
                if ($isSameCountry) {
                    $this->currentIntent = 'next_page';
                    return $this->handleNextPage($normalizedData);
                }
            }

            // 6. SEARCH & FILTER (Hanya jika RAG aktif) - DIPRIORITASKAN
            if ($ragEnabled && $this->isSearchQuery($message)) {
                $this->currentIntent = 'search';
                return $this->handleSearch($rawMessage, $message, $normalizedData, $forcedLocation);
            }

            // 4. PERTANYAAN UMUM (FAQ / Pengertian) - DIPINDAH KE BAWAH SEARCH
            $faqAnswer = $this->handleFAQ($message);
            if ($faqAnswer) {
                $this->currentIntent = 'faq';
                return $this->finalizeResponse($faqAnswer, $normalizedData);
            }

            // 1.8 TOPIC GUARD (Penyaring Topik Ketat untuk Sidang)
            $isGreeting = preg_match('/\b(halo|hai|pagi|siang|sore|malam|tanya|nanya|makasih|thanks|thank you|mks|pilih|nomor|no|nmr|#|yang lain|selanjutnya|berikutnya)\b/i', $message);
            $isSearch = $this->isSearchQuery($message);
            $isDetail = ($detailIntent !== null);
            
            // LOGIKA UTAMA: Jika RAG Aktif, WAJIB masuk salah satu kategori di atas. 
            // Jika tidak (pertanyaan random), TOLAK LANGSUNG.
            if ($ragEnabled && !$isGreeting && !$isSearch && !$isDetail) {
                $this->currentIntent = 'out_of_topic';
                return $this->finalizeResponse($this->getOutOfTopicResponse(), $normalizedData);
            }

            // 1.9 PENGECUALIAN DEFINISI (Apa itu, Pengertian, dsb)
            if ($ragEnabled && preg_match('/\b(apa itu|pengertian|definisi|jelaskan|maksud dari)\b/i', $message)) {
                $this->currentIntent = 'out_of_topic';
                return $this->finalizeResponse($this->getOutOfTopicResponse(), $normalizedData);
            }



            // 1.10 SEARCH HANDLER
            if ($ragEnabled && $isSearch) {
                $response = $this->handleSearch($rawMessage, $message, $normalizedData);
                
                // JIKA HASIL DATABASE KOSONG, JANGAN MENYERAH, TANYA AI
                $data = $response->getData();
                if (isset($data->answer) && (str_contains($data->answer, 'Maaf, saya tidak menemukan') || str_contains($data->answer, 'belum memiliki data'))) {
                    return $this->handlePureAI($rawMessage, $ragEnabled, $normalizedData);
                }
                
                return $response;
            }

            // 1.11 AI FALLBACK (Untuk pertanyaan random yang masih seputar pendidikan/beasiswa)
            $this->currentIntent = 'ai_fallback';
            return $this->handlePureAI($rawMessage, $ragEnabled, $normalizedData);

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

    private function normalizeText($text)
    {
        $text = strtolower(trim($text));
        $hasTypo = false;
        $typoWord = '';
        $correctedWord = '';

        $synonyms = [
            'beasiswa' => 'beasiswa', 'beas' => 'beasiswa', 'schol' => 'scholarship', 'scholar' => 'scholarship',
            'jurusan' => 'bidang', 'jur' => 'bidang', 'prodi' => 'bidang', 'studi' => 'bidang',
            'univ' => 'universitas', 'kampus' => 'universitas', 'uni' => 'universitas',
            'link' => 'url', 'tautan' => 'url', 'web' => 'url', 'website' => 'url', 'linknya' => 'url', 'link nya' => 'url', 'urlnya' => 'url', 'url nya' => 'url',
            'cara daftar' => 'apply', 'cr dftr' => 'apply', 'daftar gimana' => 'apply', 'daftar gmn' => 'apply', 'cara apply' => 'apply',
            'fasilitas' => 'benefit', 'keuntungan' => 'benefit', 'manfaat' => 'benefit',
            'dana penuh' => 'fully funded', 'beasiswa penuh' => 'fully funded', 'pendanaan penuh' => 'fully funded',
            'dana sebagian' => 'partially funded', 'partial funded' => 'partially funded', 'pendanaan sebagian' => 'partially funded',
            'indo' => 'indonesia', 'as' => 'amerika serikat', 'uk' => 'inggris', 'jpn' => 'jepang', 'kor' => 'korea',
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
            'infonya' => 'detail', 'liat' => 'detail', 'lihat' => 'detail', 'info' => 'detail', 'detail' => 'detail', 'selengkapnya' => 'detail',
            'syaratnya' => 'persyaratan', 'benefitnya' => 'benefit', 'deadlinenya' => 'deadline', 'caranya' => 'apply',
            'cara daftar' => 'apply', 'cr dftr' => 'apply', 'cara pendaftaran' => 'apply',
            'bole' => 'boleh', 'bisakah' => 'boleh', 'mau' => 'boleh'
        ];
        foreach ($synonyms as $key => $value) {
            // Gunakan preg_replace dengan word boundary agar tidak merusak kata lain 
            // (misal: 'no' tidak merubah 'nomor' menjadi 'nomormor')
            $text = preg_replace('/\b' . preg_quote($key, '/') . '\b/i', $value, $text);
        }

        // 2. Typo Tolerance dengan Algoritma Levenshtein (Sangat Pintar)
        // Mengecek kemiripan kata untuk mentoleransi typo
        $targetWords = [
            'benefit', 'benefitnya', 'persyaratan', 'syaratnya', 'syarat', 'deadline', 'deadlinenya', 
            'apply', 'daftar', 'daftarnya', 'pendaftaran', 'beasiswa', 'beasiswanya', 'pendanaan',
            'detail', 'boleh', 'terimakasih', 'makasih', 'negara', 'benua', 'kapan', 'gimana', 'dimana', 'bagaimana', 'cara'
        ];
        $words = explode(' ', $text);
        foreach ($words as &$word) {
            // Periksa kata dengan panjang >= 3
            if (strlen($word) >= 3) {
                foreach ($targetWords as $target) {
                    // Jika butuh maksimal 2 perubahan huruf (typo wajar) dan kata tidak sama persis
                    // Jika butuh maksimal 2 perubahan huruf (typo wajar) dan kata tidak sama persis
                    $dist = levenshtein($word, $target);
                    if ($word !== $target && $dist > 0 && $dist <= 2) {
                        // Khusus kata sangat pendek (3 huruf), hanya toleransi 1 kesalahan agar tidak salah koreksi
                        if (strlen($word) == 3 && $dist > 1) continue;

                        $hasTypo = true;
                        $typoWord = $word;
                        $correctedWord = $target;
                        $word = $target; // Tetap dikoreksi di internal text
                        break;
                    }
                }
            }
        }
        $text = implode(' ', $words);

        return [
            'text' => $text,
            'hasTypo' => $hasTypo,
            'typoWord' => $typoWord,
            'correctedWord' => $correctedWord
        ];
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

        // Catatan koreksi otomatis dihapus sesuai permintaan user agar tampilan tetap bersih
        return response()->json([
            'success' => $success,
            'answer' => $answer
        ]);
    }

    private function isGreeting($message)
    {
        $greetings = ['halo', 'hai', 'hi', 'hello', 'pagi', 'siang', 'sore', 'malam', 'permisi', 'assalamualaikum'];
        $intents = ['mau nanya', 'tanya dong', 'boleh tanya', 'nanya dong', 'saya mau tanya', 'boleh nanya', 'bisakah saya tanya', 'ada yang mau saya tanyakan', 'tanya ngga'];
        
        // Gunakan Regex agar "p" tidak mendeteksi huruf di tengah kata (seperti depok)
        $isGreet = false;
        foreach (array_merge($greetings, $intents) as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                $isGreet = true;
                break;
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

    /**
     * Memberikan jawaban salam yang dinamis berdasarkan input user
     */
    private function getGreetingResponse($message)
    {
        // Jika user minta izin bertanya
        if (str_contains($message, 'tanya') || str_contains($message, 'nanya')) {
            $responses = [
                "Tentu, silakan! Dengan senang hati saya akan membantu 😊 Apa yang ingin Anda tanyakan seputar beasiswa?",
                "Boleh banget! Apa nih yang ingin kamu tanyain seputar info beasiswa? Aku siap bantu jawab ya! 😊",
                "Silakan! ScholarBot siap membantu menjawab keraguan kamu seputar beasiswa. Mau tanya tentang apa nih? 🎓"
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
                "Hai! ScholarBot di sini siap membantu kamu cari info beasiswa terbaik. Ada yang ingin ditanyakan? 😊"
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
            // Jika pesan mengandung kata kunci pencarian atau cukup panjang, jangan anggap sebagai acknowledgment saja
            if ($this->isSearchQuery($message) || strlen($message) > 15) {
                return false;
            }
            return true;
        }
        return false;
    }

    private function isThankYou($message)
    {
        $thanks = ['terima kasih', 'terimakasih', 'makasih', 'suwun', 'thanks', 'thx', 'thank you', 'tengkyu', 'mksh', 'maturnuwun', 'tks'];
        foreach ($thanks as $word) {
            if (str_contains($message, $word)) return true;
        }
        return false;
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

    private function isSearchQuery($message)
    {
        // KEYWORD WAJIB (Must Have) - Salah satu harus ada agar dianggap pencarian beasiswa
        $mustHave = [
            'beasiswa', 'scholarship', 'kuliah', 'studi', 'daftar', 'apply', 'registrasi', 
            'pendaftaran', 's1', 's2', 's3', 'd3', 'd4', 'jenjang', 'sarjana', 'magister', 'doktor'
        ];
        
        $hasStrongKeyword = false;
        foreach ($mustHave as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $message)) {
                // Pengecualian: 'mata kuliah' bukan beasiswa
                if ($kw === 'kuliah' && preg_match('/\bmata\s+kuliah\b/i', $message) && !str_contains($message, 'beasiswa')) {
                    continue;
                }
                $hasStrongKeyword = true;
                break;
            }
        }

        // Jika tidak ada keyword wajib, cek apakah ada angka tahun (4 digit)
        if (!$hasStrongKeyword && preg_match('/\b20[0-9]{2}\b/', $message)) {
            $hasStrongKeyword = true;
        }

        // Jika tidak ada keyword wajib, cek apakah ada kombinasi (Negara/Bulan + Jurusan/Prodi)
        if (!$hasStrongKeyword) {
            $criteria = $this->extractCriteria($message);
            $hasLocationOrTime = !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['bulan']);
            $hasField = preg_match('/\b(jurusan|prodi|bidang|fakultas)\b/i', $message);
            
            if (!($hasLocationOrTime && $hasField)) {
                return false;
            }
        }

        return true;
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

    private function isOutOfTopic($message)
    {
        // Jika user sedang dalam konteks melihat detail beasiswa, beri sedikit toleransi untuk pertanyaan follow-up
        if (session()->has('selected_scholarship') || session()->has('last_search_results')) {
            // Tetap blokir kata-kata yang SANGAT tidak relevan meskipun ada session
            $extremeForbidden = [
                'resep', 'masak', 'makan', 'minum', 'tidur', 'politik', 'agama', 'sholat', 'doa', 'game', 
                'shopee', 'tokopedia', 'tiktok', 'cuaca', 'judi', 'slot', 'hack', 'bobol', 'musik', 'lagu', 
                'coding', 'pacar', 'menikah', 'sedih', 'happy', 'senang', 'bahagia', 'kecewa', 'bingung', 'bimbang',
                'bola', 'film', 'nonton', 'bioskop', 'loker', 'lowongan', 'cpns', 'pns', 'gaji', 'harga',
                'laptop', 'hp', 'handphone', 'iphone', 'android', 'berita', 'presiden', 'menteri',
                'mata kuliah', 'materi kuliah', 'materi pelajaran', 'tugas kuliah', 'ujian', 'skripsi',
                'algoritma', 'pengolahan paralel', 'struktur data', 'basis data', 'jaringan komputer'
            ];
            foreach ($extremeForbidden as $bad) {
                if (str_contains($message, $bad)) return true;
            }
            return false; 
        }

        // 1. Kata kunci utama yang WAJIB ada salah satunya agar dianggap relevan
        $scholarshipKeywords = [
            'beasiswa', 'scholarship', 'apply', 'daftar', 'pendaftaran', 'bantuan', 'dana', 'kuliah', 'studi',
            'deadline', 'ielts', 'toefl', 'loa', 's1', 's2', 's3', 'd3', 'd4', 'syarat', 'persyaratan', 
            'benefit', 'biaya', 'funding', 'pendanaan', 'negara', 'benua', 'akademik', 'edukasi', 'ukt', 'ipk', 'gpa',
            'graduate', 'mampu', 'miskin', 'perempuan', 'semester', 'wawancara', 'interview', 'detail', 'info', 'link'
        ];

        $hasScholarshipContext = false;
        foreach ($scholarshipKeywords as $kw) {
            if (str_contains($message, $kw)) {
                $hasScholarshipContext = true;
                break;
            }
        }

        // Jika ada konteks beasiswa, anggap masuk topik
        if ($hasScholarshipContext) return false;

        // Blacklist kata-kata yang SANGAT tidak relevan (seperti resep, judi, dsb)
        $extremeForbidden = [
            'resep', 'masak', 'makan', 'minum', 'game', 'judi', 'slot', 'hack', 'pacar', 'bola', 'film'
        ];
        foreach ($extremeForbidden as $bad) {
            if (str_contains($message, $bad)) return true;
        }

        // Jika pesan pendek dan tidak ada keyword beasiswa, anggap OOT
        return strlen($message) > 15; 
    } 

    private function handleFAQ($message)
    {
        $m = strtolower($message);
        
        // List pertanyaan titipan user (Possibility)
        if (str_contains($m, 'lpdp') && (str_contains($m, 'kapan') || str_contains($m, 'buka'))) {
            return "Beasiswa LPDP biasanya dibuka dalam 2 tahap setiap tahunnya (sekitar bulan Januari-Februari untuk Tahap 1 dan Juni-Juli untuk Tahap 2). Untuk update resmi tahun 2026, silakan pantau terus situs lpdp.kemenkeu.go.id ya! 😊";
        }
        if (str_contains($m, 'tanpa toefl') || str_contains($m, 'tanpa ielts')) {
            return "Ada beberapa beasiswa yang tidak mewajibkan TOEFL/IELTS, seperti beasiswa Turkiye Burslari (Turki), GKS (Korea - jalur tertentu), atau beasiswa Pemerintah Rusia. Beberapa beasiswa dalam negeri juga banyak yang tidak memerlukan sertifikat bahasa Inggris. Mau saya carikan yang spesifik? 😊";
        }
        if (str_contains($m, 'wawancara') && str_contains($m, 'tidak pakai')) {
            return "Beasiswa tanpa wawancara biasanya fokus pada seleksi berkas dan nilai akademik. Contohnya beberapa beasiswa bantuan UKT atau beasiswa dari yayasan swasta tertentu. Namun mayoritas beasiswa bergengsi biasanya tetap menyertakan tahap wawancara.";
        }
        if (str_contains($m, 'fresh graduate')) {
            return "Tentu! Banyak beasiswa S2 luar negeri yang sangat terbuka untuk fresh graduate tanpa syarat pengalaman kerja, seperti beasiswa Erasmus+ (Eropa), MEXT (Jepang), atau beasiswa dari universitas di Taiwan.";
        }
        if (str_contains($m, 'kurang mampu') || str_contains($m, 'anak mampu')) {
            return "Untuk mahasiswa kurang mampu, pilihan utamanya adalah KIP Kuliah (untuk dalam negeri) atau beasiswa yang berbasis 'Need-based Financial Aid' untuk luar negeri. Kami memiliki beberapa data bantuan kuliah tersebut di database.";
        }

        if (str_contains($m, 'perbedaan') && str_contains($m, 'fully funded') && str_contains($m, 'partially funded')) {
            return "Perbedaan utamanya:\n- **Fully Funded** menanggung seluruh biaya (kuliah, hidup, tiket, dll).\n- **Partially Funded** hanya menanggung sebagian biaya (misal hanya biaya kuliah atau uang saku saja).";
        }
        
        // Deteksi Pengertian (Hanya jika 'apa itu' secara spesifik)
        if (preg_match('/\b(apa itu|pengertian|maksud dari|definisi)\b/i', $m)) {
            if (str_contains($m, 'fully funded') || str_contains($m, 'full funded')) {
                return "Fully Funded adalah jenis beasiswa yang menanggung seluruh biaya studi, biasanya mencakup biaya kuliah (tuition fee), biaya hidup (living allowance), asuransi kesehatan, hingga tiket pesawat.";
            }
            if (str_contains($m, 'partially funded') || str_contains($m, 'partial funded')) {
                return "Partially Funded adalah beasiswa yang hanya menanggung sebagian biaya studi, misalnya hanya membiayai uang kuliah saja (tuition only) tanpa biaya hidup, atau sebaliknya.";
            }
        }

        if (str_contains($m, 'apa itu ielts')) {
            return "IELTS (International English Language Testing System) adalah tes standar internasional untuk mengukur kemampuan bahasa Inggris bagi mereka yang ingin kuliah atau bekerja di negara berbahasa Inggris.";
        }
        if (str_contains($m, 'apa itu loa')) {
            return "LoA (Letter of Acceptance) adalah surat resmi dari universitas yang menyatakan bahwa Anda telah diterima sebagai mahasiswa di universitas tersebut. LoA sering menjadi salah satu syarat mendaftar beasiswa.";
        }
        return null;
    }

    // Fungsi handleSelection dihapus karena logikanya sudah terintegrasi di ask()
    
    private function getDetailIntent($m)
    {
        $m = strtolower($m);
        
        // GUNAKAN PREG_MATCH DENGAN WORD BOUNDARY (\b) AGAR TIDAK SALAH TANGKAP
        if (preg_match('/\b(url|link|tautan|web|website)\b/i', $m)) return 'url';
        if (preg_match('/\b(daftar|apply|registrasi|gabung|join)\b/i', $m)) return 'apply';
        if (preg_match('/\b(benefit|tunjangan|fasilitas|dana|biaya|funding)\b/i', $m)) {
            if (preg_match('/\b(dana|biaya|funding)\b/i', $m)) return 'funding';
            return 'benefit';
        }
        if (preg_match('/\b(syarat|persyaratan|kualifikasi)\b/i', $m)) return 'persyaratan';
        if (preg_match('/\b(deadline|batas|tutup)\b/i', $m)) return 'deadline';
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
                // UJI COBA 3: Jika data di dataset kosong/minim, gunakan AI untuk melengkapi
                if ($content === '-' || strlen($content) < 20) {
                    return $this->handlePureAI("Tolong jelaskan apa saja benefit atau cakupan beasiswa dari {$name} secara detail.", true, $normalizedData, true);
                }
                $translated = $this->translateToIndonesian($content);
                $ans = "Benefit **$name**:\n" . $translated; 
                break;
            case 'persyaratan': 
            case 'syarat': 
                $content = $selected['persyaratan'] ?? '-';
                // UJI COBA 3: Jika data di dataset kosong/minim, gunakan AI untuk melengkapi
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
                $urlPendaftaran = $selected['url'] ?? null;
                $urlUniv = $selected['url_asli'] ?? null;
                
                $link1 = $urlPendaftaran ? "[Link Pendaftaran]($urlPendaftaran)" : "_Belum tersedia_";
                $link2 = $urlUniv ? "[Website Resmi]($urlUniv)" : "_Belum tersedia_";

                $ans = "Berikut ini adalah link beasiswa **$name**: $link1, $link2";
                break;
            case 'apply':
                $url = route('scholarship.detail', ['id' => $selected['id'] ?? 0]);
                $ans = "Untuk melihat cara pendaftaran lengkap **$name**, silakan kunjungi halaman ini:\n\n👉 [**Buka Halaman Detail & Cara Daftar**]($url)\n\nAtau langsung ke website resmi: " . ($selected['url'] ?? '-');
                break;
            case 'detail':
                $ans = "Berikut ringkasan **$name**:\n\n" .
                       "📍 **Negara**: " . ($selected['negara'] ?? '-') . " " .
                       "🎓 **Jenjang**: " . ($selected['jenjang'] ?? '-') . " " .
                       "🗓️ **Deadline**: " . ($selected['deadline'] ?? '-') . " " .
                       "💰 **Pendanaan**: " . ($selected['kategori'] ?? '-') . " " .
                       "📚 **Jurusan**: " . ($selected['bidang'] ?? 'Semua jurusan') . "\n\n" .
                       "Ketik **Benefit**, **Syarat**, **Cara Daftar**, atau **Link** untuk info lebih lanjut.";
                break;
        }
        return $this->finalizeResponse($ans, $normalizedData);
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
            // Gunakan penomoran berlanjut (misal: 6. 7. 8...)
            $displayNumber = $startIndex + $i + 1;
            $resp .= $displayNumber . "\. **{$namaBeasiswa}** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ")\n\n";
        }

        if (count($allResults) > $startIndex + 10) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau silakan pilih nomor beasiswa untuk melihat detail.";
        } else {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat detail seperti benefit, syarat, deadline, atau cara daftar.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function handleSearch($rawText, $message, $normalizedData, $forcedLocation = null)
    {
        $criteria = $this->extractCriteria($message);
        if ($forcedLocation) $criteria['lokasi_tipe'] = $forcedLocation;
        if ($forcedLocation === 'dalam' && empty($criteria['negara'])) $criteria['negara'][] = 'indonesia';

        // Pengecekan Tahun (Poin Tambahan)
        if (!empty($criteria['tahun'])) {
            $is2026 = in_array('2026', $criteria['tahun']);
            if (!$is2026) {
                return $this->finalizeResponse("Mohon maaf, ScholarBot hanya menyediakan data 2026 untuk saat ini, terima kasih 😊", $normalizedData);
            }
        }

        // CONTEXT MERGING: Jika kriteria baru minim, gunakan kriteria dari pencarian sebelumnya (Point 9)
        // CONTEXT MERGING & RESET (Point 9)
        if (session()->has('last_search_criteria')) {
            $lastCriteria = session()->get('last_search_criteria');
            
            // JIKA user menyebutkan kriteria baru yang kuat (Negara/Jurusan/Tahun), 
            // maka kita RESET kriteria lama agar tidak campur aduk.
            $hasNewStrongCriteria = !empty($criteria['negara']) || !empty($criteria['bidang']) || !empty($criteria['tahun']);
            
            if (!$hasNewStrongCriteria) {
                // Gunakan memori lama jika tidak ada kriteria baru
                if (empty($criteria['negara']) && !empty($lastCriteria['negara'])) {
                    $criteria['negara'] = $lastCriteria['negara'];
                }
                if (empty($criteria['jenjang']) && !empty($lastCriteria['jenjang'])) {
                    $criteria['jenjang'] = $lastCriteria['jenjang'];
                }
            }
        }

        // Tentukan query untuk embedding. Jika query sangat pendek (follow-up), 
        // gunakan gabungan kriteria untuk hasil pencarian yang lebih relevan.
        $searchQuery = $rawText;
        if (strlen($message) < 15 && !empty($criteria['negara'])) {
            $searchQuery = "beasiswa " . implode(' ', $criteria['negara']);
        }

        if (preg_match('/\b(paling dekat|deadline dekat|mepet|terdekat)\b/i', $message)) {
            $criteria['sort_deadline'] = true;
        }

        $vStart = microtime(true);
        $embedding = $this->generateEmbedding($searchQuery);
        $searchIds = DB::select("SELECT id FROM hybrid_search(?::text, ?::vector, ?::int)", [
            $searchQuery, '[' . implode(',', $embedding) . ']', 100
        ]);
        $this->vectorSearchTime += (microtime(true) - $vStart);
        
        $ids = array_map(fn($r) => $r->id, $searchIds);
        
        if (empty($ids)) {
            $rawResults = [];
        } else {
            // Ambil data lengkap dengan cara yang lebih ringan
            $rawResults = DB::table('scholarships')
                ->whereIn('id', $ids)
                ->get()
                ->all();
            
            // Urutkan kembali di level PHP
            $idMap = array_flip($ids);
            usort($rawResults, function($a, $b) use ($idMap, $criteria) {
                // JIKA USER MINTA DEADLINE TERDEKAT
                if (!empty($criteria['sort_deadline'])) {
                    $dA = strtotime($a->deadline ?? '9999-12-31');
                    $dB = strtotime($b->deadline ?? '9999-12-31');
                    return $dA - $dB;
                }
                return ($idMap[$a->id] ?? 999) - ($idMap[$b->id] ?? 999);
            });
        }

        $filteredRaw = $this->applyStrictFilters($rawResults, $criteria);

        // Filter Tambahan Manual untuk memastikan (Double Check)
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

        // VARIASI DATA: Jika pencarian sangat umum
        if (empty($criteria['negara']) && empty($criteria['benua']) && empty($criteria['bidang'])) {
            shuffle($filtered);
        }

        if (empty($filtered)) {
            if (!empty($criteria['negara'])) {
                return $this->finalizeResponse("Maaf, saya belum memiliki data beasiswa untuk negara tersebut.", $normalizedData);
            }
            return $this->finalizeResponse("Maaf, saya tidak menemukan beasiswa yang sesuai dengan pencarian tersebut.", $normalizedData);
        }

        $limitedResults = array_slice($filtered, 0, 5); // Tampil 5 data per halaman
        
        session()->put('last_search_all_results', $filtered);
        session()->put('last_search_criteria', $criteria);
        session()->put('last_search_page', 1);
        session()->put('last_search_results', $limitedResults);
        session()->forget('selected_scholarship');

        $locContext = $this->getLocContext($criteria);
        $count = count($filtered);
        $isQuantification = $this->isQuantificationQuery($message);

        // Point 13: Format khusus untuk bulan
        $isMonthSearch = !empty($criteria['bulan']);

        if ($isQuantification) {
            $resp = "Total beasiswa yang ditemukan{$locContext} adalah **$count** beasiswa.\n\nBerikut rinciannya:\n\n";
        } else {
            $headerParts = [];
            if (!empty($criteria['bidang'])) $headerParts[] = "jurusan " . implode(', ', array_map('ucwords', $criteria['bidang']));
            if (!empty($criteria['jenjang'])) $headerParts[] = "jenjang " . implode('/', $criteria['jenjang']);
            if (!empty($criteria['negara'])) $headerParts[] = "di " . implode(', ', array_map('ucwords', $criteria['negara']));
            
            $resp = "Berikut daftar beasiswa " . implode(' ', $headerParts) . ":\n\n";
        }

        foreach ($limitedResults as $i => $s) {
            $s = (array) $s;
            $namaBeasiswa = trim($s['nama_beasiswa']);
            if ($isMonthSearch) {
                // Point 13: Nama Beasiswa - Deadline
                $resp .= ($i + 1) . ". **{$namaBeasiswa}** - " . ($s['deadline'] ?? '-') . "\n\n";
            } else {
                $resp .= ($i + 1) . ". **{$namaBeasiswa}** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ")\n\n";
            }
        }

        if (count($filtered) > 10) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya.";
        } elseif ($count > 0) {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat detail seperti benefit, syarat, deadline, atau cara daftar.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function extractCriteria($text)
    {
        $c = [
            'negara' => [], 
            'benua' => [], 
            'jenjang' => [], 
            'bulan' => [], 
            'tahun' => [],
            'semester' => null,
            'funding' => null, 
            'negara_ori' => null, 
            'bidang' => [],
            'lokasi_tipe' => null // 'luar' atau 'dalam'
        ];
        
        // 0. Deteksi Tahun (Menangkap 2000 - 2099)
        if (preg_match_all('/\b(20[0-9]{2})\b/', $text, $yearMatches)) {
            $c['tahun'] = $yearMatches[0];
        }

        // 0.1 Deteksi Semester
        if (preg_match('/\bsemester\s+([0-9]|akhir)\b/i', $text, $semMatches)) {
            $c['semester'] = $semMatches[1];
        }
        $majors = [
            'matematika', 'statistika', 'fisika', 'kimia', 'biologi', 'kedokteran', 'farmasi',
            'teknik', 'arsitektur', 'komputer', 'informatika', 'hukum', 'ekonomi', 'akuntansi',
            'manajemen', 'bisnis', 'psikologi', 'pertanian', 'kehutanan', 'perikanan', 'peternakan',
            'seni', 'desain', 'komunikasi', 'sastra', 'pendidikan', 'politik', 'hubungan internasional',
            'geografi', 'lingkungan', 'sejarah', 'filsafat', 'sosiologi', 'arkeologi', 'astronomi',
            'teknologi', 'sains', 'it', 'ilmiah', 'psikiatri', 'keperawatan', 'kebidanan', 'gizi'
        ];
        foreach ($majors as $m) {
            if (preg_match('/\b' . preg_quote($m, '/') . '\b/i', $text)) {
                $c['bidang'][] = $m;
            }
        }

        // 2. Sinonim Manual Negara (Paling Prioritas)
        $syns = [
            'amerika' => 'amerika serikat',
            'usa' => 'amerika serikat',
            'as' => 'amerika serikat',
            'swiss' => 'swiss', 
            'jepang' => 'jepang', 
            'belgia' => 'belgia', 
            'belanda' => 'belanda', 
            'inggris' => 'inggris',
            'uk' => 'inggris',
            'saudi arabia' => 'arab saudi',
            'korea' => 'korea',
            'korea selatan' => 'korea selatan',
            'turki' => 'turki',
            'turkey' => 'turki'
        ];

        foreach ($syns as $key => $target) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/i', $text)) {
                // Khusus 'amerika', jangan masukkan ke filter negara jika user menyebut 'benua'
                if ($key === 'amerika' && preg_match('/\bbenua\s+amerik[a-z]*\b/i', $text)) {
                    continue;
                }
                $c['negara'][] = $target;
                if (empty($c['negara_ori'])) $c['negara_ori'] = ucwords($key);
            }
        }

        // 2. Deteksi Negara dari Database (Tambahan jika belum ada atau untuk deteksi lebih luas)
        $allCountriesRaw = DB::table('scholarships')->distinct()->whereNotNull('negara')->pluck('negara')->toArray();
        $allCountries = [];
        foreach ($allCountriesRaw as $raw) {
            $parts = explode(',', str_replace(['luar negeri (', 'dalam negeri (', ')'], '', strtolower($raw)));
            foreach ($parts as $p) {
                $p = trim($p);
                if (!empty($p)) $allCountries[] = $p;
            }
        }
        $allCountries = array_unique($allCountries);
        
        // Sortir negara berdasarkan panjang (terpanjang dulu) agar tidak salah deteksi (misal: "India" vs "Indonesia")
        usort($allCountries, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($allCountries as $country) {
            if (preg_match('/\b' . preg_quote($country, '/') . '\b/i', $text)) {
                $c['negara'][] = $country;
                if (empty($c['negara_ori'])) $c['negara_ori'] = ucwords($country);
            }
        }
        $c['negara'] = array_unique($c['negara']);

        // 3. Deteksi Benua
        $continents = ['eropa', 'asia', 'australia', 'afrika', 'amerika'];
        foreach ($continents as $con) {
            // Gunakan Regex dengan word boundary agar "beasiswa" tidak terdeteksi sebagai "asia"
            // Mendukung pencarian "Amerika" dengan typo atau bahasa Inggris (America)
            // Mendukung pencarian "Australia" dengan Oseania
            if ($con === 'amerika') {
                $pattern = '/\b(amerik|americ)[a-z]*\b/i';
            } elseif ($con === 'australia') {
                $pattern = '/\b(australia|oseania|oceania)\b/i';
            } else {
                $pattern = '/\b' . preg_quote($con, '/') . '\b/i';
            }
            
            if (preg_match($pattern, $text)) {
                $c['benua'][] = $con;
            }
        }

        // 4. Deteksi Tipe Lokasi (Luar/Dalam Negeri)
        $lowerText = strtolower($text);
        if (preg_match('/\b(luar negeri|international|abroad|luar)\b/i', $lowerText)) {
            $c['lokasi_tipe'] = 'luar';
        } elseif (preg_match('/\b(dalam negeri|domestic|local|indonesia|indo)\b/i', $lowerText)) {
            $c['lokasi_tipe'] = 'dalam';
            $c['negara'][] = 'indonesia'; // Paksa tambah indonesia agar filter akurat
        }

        // 5. Deteksi Jenjang, Bulan, dan Funding
        if (preg_match('/\b(bulan ini|saat ini|sekarang|belum lewat|masih buka)\b/i', $lowerText)) {
            $currentMonth = strtolower(now()->translatedFormat('F')); 
            $c['bulan'][] = $currentMonth;
        }

        foreach (['s1', 's2', 's3', 'd3', 'd4'] as $l) if (str_contains($text, $l)) $c['jenjang'][] = strtoupper($l);
        
        $months = [
            'januari' => ['januari', 'jan'],
            'februari' => ['februari', 'pebruari', 'febuari', 'pebuari', 'feb', 'peb'],
            'maret' => ['maret', 'mar'],
            'april' => ['april', 'apr'],
            'mei' => ['mei'],
            'juni' => ['juni', 'jun'],
            'juli' => ['juli', 'jul'],
            'agustus' => ['agustus', 'agu', 'agt'],
            'september' => ['september', 'sep'],
            'oktober' => ['oktober', 'okt'],
            'november' => ['november', 'nopember', 'nov'],
            'desember' => ['desember', 'des']
        ];
        foreach ($months as $m_key => $variants) {
            foreach ($variants as $v) {
                if (str_contains($text, $v)) {
                    $c['bulan'][] = $m_key;
                    break;
                }
            }
        } 
        
        // PENDANAAN
        if (preg_match('/\b(gratis full|full gratis|dana penuh|fully funded|biaya penuh)\b/i', $text)) {
            $c['funding'] = 'Fully Funded';
        } elseif (preg_match('/\b(ukt doang|sebagian|partially funded|biaya sebagian)\b/i', $text)) {
            $c['funding'] = 'Partially Funded';
        }

        // KEYWORD KHUSUS
        if (preg_match('/\b(tanpa toefl|tanpa ielts|no toefl|no ielts)\b/i', $text)) $c['no_test'] = true;
        if (preg_match('/\b(kurang mampu|miskin|ekonomi lemah|kip)\b/i', $text)) $c['ekonomi'] = true;
        if (preg_match('/\b(perempuan|wanita|khusus cewek)\b/i', $text)) $c['gender'] = 'perempuan';
        if (preg_match('/\b(fresh graduate|lulusan baru)\b/i', $text)) $c['fresh_grad'] = true;
        if (preg_match('/\b(tanpa wawancara|no interview)\b/i', $text)) $c['no_interview'] = true;
        if (preg_match('/\b(gpa di bawah 3|ipk rendah|ipk di bawah 3)\b/i', $text)) $c['low_gpa'] = true;

        return $c;
    }

    private function applyStrictFilters($results, $criteria)
    {
        $filtered = array_filter($results, function($r) use ($criteria) {
            // Filter Khusus (Check Deskripsi & Persyaratan)
            $content = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? ''));
            
            if (!empty($criteria['no_test']) && (str_contains($content, 'toefl') || str_contains($content, 'ielts'))) {
                if (!str_contains($content, 'tanpa toefl') && !str_contains($content, 'tanpa ielts') && !str_contains($content, 'tidak wajib toefl')) return false;
            }
            if (!empty($criteria['gender']) && $criteria['gender'] === 'perempuan' && !str_contains($content, 'perempuan') && !str_contains($content, 'wanita')) return false;
            if (!empty($criteria['ekonomi']) && !str_contains($content, 'kurang mampu') && !str_contains($content, 'ekonomi') && !str_contains($content, 'kip')) return false;
            if (!empty($criteria['fresh_grad']) && !str_contains($content, 'fresh graduate') && !str_contains($content, 'lulusan baru')) return false;
            if (!empty($criteria['no_interview']) && str_contains($content, 'wawancara')) {
                if (!str_contains($content, 'tanpa wawancara')) return false;
            }

            // Filter Tipe Lokasi (Luar/Dalam Negeri) - MAXIMUM STRICTION
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
                    'januari' => ['januari', 'jan'],
                    'februari' => ['februari', 'pebruari', 'febuari', 'pebuari', 'feb', 'peb'],
                    'maret' => ['maret', 'mar'],
                    'april' => ['april', 'apr'],
                    'mei' => ['mei'],
                    'juni' => ['juni', 'jun'],
                    'juli' => ['juli', 'jul'],
                    'agustus' => ['agustus', 'agu', 'agt'],
                    'september' => ['september', 'sep'],
                    'oktober' => ['oktober', 'okt'],
                    'november' => ['november', 'nopember', 'nov'],
                    'desember' => ['desember', 'des']
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
                
                if ($target === 'partially funded') {
                    if (!str_contains($actual, 'partially')) return false;
                } else {
                    if ($actual !== $target) return false;
                }
            }

            // Filter Tahun (Hanya jika kolom deadline mengandung tahun tersebut)
            if (!empty($criteria['tahun'])) {
                $matchYear = false;
                $deadlineStr = $r->deadline ?? '';
                $nameStr = $r->nama_beasiswa ?? '';
                foreach ($criteria['tahun'] as $y) {
                    if (str_contains($deadlineStr, $y) || str_contains($nameStr, $y)) {
                        $matchYear = true;
                        break;
                    }
                }
            }

            return true;
        });

        // DEDUPLIKASI: Hapus beasiswa dengan nama yang sama (Case Insensitive)
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
            // Google Gemini Embedding API
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

    /**
     * Menerjemahkan teks ke Bahasa Indonesia menggunakan AI (OpenRouter)
     */
    private function translateToIndonesian($text)
    {
        if (empty($text) || $text === '-' || strlen($text) < 10) return $text;

        // Cek apakah teks mengandung banyak kata bahasa Inggris (deteksi lebih luas)
        $englishKeywords = ['scholarship', 'requirements', 'eligibility', 'benefits', 'citizenship', 'degree', 'deadline', 'tuition', 'award', 'allowance', 'internship', 'public', 'service', 'fees', 'maintenance', 'the', 'and', 'of', 'for', 'with'];
        $isEnglish = false;
        foreach ($englishKeywords as $kw) {
            if (str_contains(strtolower($text), $kw)) {
                $isEnglish = true;
                break;
            }
        }

        // Jika tidak terdeteksi bahasa Inggris, kirim apa adanya (hemat kuota)
        if (!$isEnglish) return $text;

        try {
            $apiKey = trim(env('OPENAI_API_KEY'));
            $baseUrl = str_contains($apiKey, 'sk-or') ? 'https://openrouter.ai/api/v1' : 'https://api.openai.com/v1';
            
            // Gunakan model yang murah/cepat untuk translasi
            $model = str_contains($apiKey, 'sk-or') ? 'google/gemini-2.0-flash-lite-001' : 'gpt-3.5-turbo';

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

        return $text; // Balikkan teks asli jika gagal
    }
    /**
     * Menangani pertanyaan langsung ke AI tanpa bantuan Database (Pure AI Mode)
     */
    private function handlePureAI($message, $ragEnabled = false, $normalizedData = null, $forceContext = false)
    {
        try {
            $apiKey = trim(env('OPENAI_API_KEY'));
            
            if (empty($apiKey)) {
                return $this->finalizeResponse("Maaf, konfigurasi API Key tidak ditemukan. Silakan hubungi admin.", $normalizedData, false);
            }

            if (str_contains($apiKey, 'sk-or')) {
                $baseUrl = 'https://openrouter.ai/api/v1';
                $model = 'google/gemini-2.0-flash-lite-001';
            } elseif (str_starts_with($apiKey, 'AIza')) {
                // Google Gemini Direct API
                $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
                $model = 'gemini-1.5-flash';
            } else {
                $baseUrl = 'https://api.openai.com/v1';
                $model = 'gpt-3.5-turbo';
            }

            $systemPrompt = 'Anda adalah ScholarBot, asisten AI informasi beasiswa. ';
            
            if ($forceContext) {
                $systemPrompt .= 'Anda adalah ahli beasiswa. Berikan jawaban yang sangat detail dan akurat mengenai beasiswa yang ditanyakan oleh user. Gunakan pengetahuan luas Anda karena data di database kami sedang tidak lengkap.';
            } elseif ($ragEnabled) {
                $systemPrompt .= 'Saat ini Anda beroperasi dalam MODE RAG. 
                Tugas Anda:
                1. Berikan informasi beasiswa berdasarkan Context Beasiswa yang diberikan di bawah.
                2. Jika informasi tidak ada di context, gunakan pengetahuan luas Anda untuk menjawab asalkan tetap dalam TOPIK BEASISWA.
                3. JIKA pertanyaan user di luar topik beasiswa, pendidikan, atau universitas (misal: tanya nama, lokasi umum, hobi, dsb), Anda WAJIB menolak dengan kalimat persis seperti ini:
                   "Mohon maaf, chatbot kami tidak menerima pertanyaan diluar informasi beasiswa, jika ingin bertanya hal tersebut bisa anda off kan toggle diatas dan silahkan ulangi pertanyaannya."';
                
                // Tambahkan context beasiswa ke prompt
                $context = $this->getScholarshipContext($message);
                $systemPrompt .= "\n\nContext Beasiswa dari Dataset Kami:\n" . $context;
                $systemPrompt .= "\n\nINSTRUKSI PENTING: 
                - Jika pertanyaan user adalah tentang tahun di masa depan (seperti 2026, 2030) dan data di context kami tidak ada, jelaskan bahwa data resmi belum rilis tapi berikan estimasi berdasarkan jadwal tahunan beasiswa tersebut.
                - Jika user bertanya hal yang tidak mungkin (Hogwarts, Mars, NASA untuk SMP), jawablah dengan sopan bahwa itu tidak tersedia atau hanya fiksi, tetap dengan gaya bahasa asisten beasiswa yang ramah.
                - Jika data context kosong, tetaplah membantu menjawab menggunakan pengetahuan umum Anda asalkan tetap bertema Beasiswa/Pendidikan.";
            } else {
                $systemPrompt .= 'Saat ini Anda beroperasi dalam MODE STANDAR (General AI). 
                Jawablah pertanyaan user secara bebas dan ramah tentang TOPIK APAPUN. 
                Anda tidak perlu membatasi diri pada beasiswa karena Mode RAG sedang dimatikan.';
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
                
                // Tambahkan label mode untuk transparansi
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

        // 1. JIKA ADA REFERENSI NOMOR SPESIFIK (Misal: "no 2 ada jurusan X?")
        if (preg_match('/\b(?:no|nomor|#|ke)\s*([0-9]+)\b/i', $ref, $refMatches)) {
            $num = (int)$refMatches[1];
            $results = session()->get('last_search_all_results', []);
            if (isset($results[$num - 1])) {
                $s = (array)$results[$num - 1];
                $bidang = strtolower($s['bidang'] ?? '');
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

        // 2. JIKA ADA BEASISWA YANG SEDANG DIPILIH (Dan bukan nanya nomor lain)
        if (session()->has('selected_scholarship') && ($ref === 'ini' || $ref === 'itu' || $ref === 'tersebut' || $ref === '')) {
            $s = session()->get('selected_scholarship');
            $bidang = strtolower($s['bidang'] ?? '');
            $persyaratan = strtolower($s['persyaratan'] ?? '');
            $benefit = strtolower($s['benefit'] ?? '');
            $nama = $s['nama_beasiswa'];
            
            // Cek di kolom bidang, persyaratan, atau benefit
            if (str_contains($bidang, $major) || str_contains($persyaratan, $major) || str_contains($benefit, $major)) {
                return $this->finalizeResponse("Iya, beasiswa **$nama** tersedia untuk jurusan **" . ucwords($major) . "**. 😊\n\nApa lagi yang ingin Anda ketahui? (Ketik: **Benefit**, **Syarat**, **Deadline**, atau **Cara Daftar**)", $normalizedData);
            } else {
                return $this->finalizeResponse("Mohon maaf, sepertinya beasiswa **$nama** tidak secara spesifik menyebutkan ketersediaan untuk jurusan **" . ucwords($major) . "**. Namun Anda bisa mencoba mengecek detail syarat lengkapnya dengan mengetik **'Syarat'** atau mencari beasiswa lain. 😊", $normalizedData);
            }
        }

        // 2. JIKA TIDAK ADA YANG DIPILIH, CEK LIST TERAKHIR (Validasi nomor)
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
        
        // Normalisasi tipe pendanaan
        if (str_contains($target, 'full') || str_contains($target, 'penuh')) {
            $targetType = 'fully funded';
            $displayType = 'Fully Funded';
        } else {
            $targetType = 'partially funded';
            $displayType = 'Partially Funded';
        }

        // 1. CEK REFERENSI NOMOR
        if (preg_match('/\b(?:no|nomor|#|ke)\s*([0-9]+)\b/i', $ref, $refMatches)) {
            $num = (int)$refMatches[1];
            $results = session()->get('last_search_all_results', []);
            if (isset($results[$num - 1])) {
                $s = (array)$results[$num - 1];
                $actual = strtolower($s['kategori'] ?? '');
                $nama = $s['nama_beasiswa'];
                
                if (str_contains($actual, $targetType) || ($targetType === 'fully funded' && str_contains($actual, 'penuh'))) {
                    return $this->finalizeResponse("Iya, beasiswa nomor $num (**$nama**) adalah beasiswa **$displayType**. 😊", $normalizedData);
                } else {
                    $actualDisplay = str_contains($actual, 'fully') ? 'Fully Funded' : (str_contains($actual, 'partially') ? 'Partially Funded' : ucwords($actual));
                    return $this->finalizeResponse("Bukan, beasiswa nomor $num (**$nama**) kategorinya adalah **$actualDisplay**, bukan $displayType. 😊", $normalizedData);
                }
            }
        }

        // 2. CEK SELECTED SCHOLARSHIP (Konteks "ini/itu")
        if (session()->has('selected_scholarship') && ($ref === 'ini' || $ref === 'itu' || $ref === 'tersebut' || $ref === '')) {
            $s = session()->get('selected_scholarship');
            $actual = strtolower($s['kategori'] ?? '');
            $nama = $s['nama_beasiswa'];
            
            if (str_contains($actual, $targetType) || ($targetType === 'fully funded' && str_contains($actual, 'penuh'))) {
                return $this->finalizeResponse("Iya, beasiswa **$nama** ini adalah beasiswa **$displayType**. 😊", $normalizedData);
            } else {
                $actualDisplay = str_contains($actual, 'fully') ? 'Fully Funded' : (str_contains($actual, 'partially') ? 'Partially Funded' : ucwords($actual));
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
            "Mohon maaf sekali, pertanyaan Anda di luar topik beasiswa. ScholarBot fokus pada bantuan informasi mengenai beasiswa"
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
}

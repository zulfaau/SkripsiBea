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

    /** Cache per-request agar extractCriteria & query distinct negara tidak dijalankan berulang. */
    private $criteriaCache = [];
    private $allCountriesCache = null;

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

            // 1.3 DEFINITION EXCEPTION CHECK (Apa itu, Pengertian, dsb)
            if ($ragEnabled && preg_match('/\b(apa itu|pengertian|definisi|jelaskan|maksud dari|maksud|dimaksud)\b/i', $message)) {
                // Jangan blokir jika menanyakan definisi beasiswa/syarat beasiswa
                if (!preg_match('/\b(fully funded|partially funded|full funded|partial funded|loa|ielts|toefl|lpdp|erasmus|mext|kip|beasiswa)\b/i', $message)) {
                    $this->currentIntent = 'out_of_topic';
                    return $this->finalizeResponse($this->getOutOfTopicResponse(), $normalizedData);
                }
            }

            // 1.5 THANK YOU DETECTION
            if ($this->isThankYou($message)) {
                $this->currentIntent = 'thank_you';
                return $this->finalizeResponse($this->getThankYouResponse(), $normalizedData);
            }

            // 1.7 INTENT DETECTION (Deteksi niat user)
            $detailIntent = $this->getDetailIntent($message);

            // 1.7.5 VALIDASI KRITERIA BEASISWA (Konteks Follow-up: Apakah beasiswa ini di negara/benua/jenjang/jurusan/deadline/funding X?)
            if ($ragEnabled && (session()->has('selected_scholarship') || session()->has('last_search_results'))) {
                // Deteksi kata rujukan: "ini", "itu", "tersebut", atau nomor "no 4"
                $ref = null;
                if (preg_match('/\b(?:no|nomor|#|ke)\s*([0-9]+)\b/i', $message, $refMatches)) {
                    $ref = 'no ' . $refMatches[1];
                } elseif (preg_match('/\b(ini|itu|tersebut)\b/i', $message, $refMatches)) {
                    $ref = strtolower($refMatches[1]);
                }

                // Kita juga toleransi jika tidak ada kata rujukan eksplisit tapi ada selected_scholarship di session
                $hasContext = !empty($ref) || session()->has('selected_scholarship');

                if ($hasContext) {
                    $criteria = $this->extractCriteria($message);
                    
                    // Cek apakah ada kriteria yang ingin divalidasi
                    $hasValidationCriteria = !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['jenjang']) || !empty($criteria['bidang']) || !empty($criteria['bulan']) || !empty($criteria['funding']);
                    
                    // Kita pastikan ini adalah pertanyaan interogatif/validasi (mengandung kata tanya atau pola apakah/ada/kah)
                    // Atau jika ada kata rujukan dan kriteria spesifik
                    $isValidationQuery = preg_match('/\b(apakah|ada|kah|beneran|sih|bukan|ga|gak|ndak|ya|iya|tanya)\b/i', $message) || (!empty($ref) && $hasValidationCriteria);

                    if ($hasValidationCriteria && $isValidationQuery) {
                        // Dapatkan beasiswa target
                        $targetScholarship = null;
                        if ($ref && str_starts_with($ref, 'no ')) {
                            $num = (int)str_replace('no ', '', $ref);
                            $results = session()->get('last_search_all_results', []);
                            if (isset($results[$num - 1])) {
                                $targetScholarship = (array)$results[$num - 1];
                            }
                        } elseif (session()->has('selected_scholarship')) {
                            $targetScholarship = (array)session()->get('selected_scholarship');
                        }

                        if ($targetScholarship) {
                            $nama = $targetScholarship['nama_beasiswa'];

                            // 1. Validasi Jurusan / Bidang
                            if (!empty($criteria['bidang'])) {
                                $matchedMajors = [];
                                $bidang = strtolower($targetScholarship['jurusan'] ?? '');
                                $persyaratan = strtolower($targetScholarship['persyaratan'] ?? '');
                                $benefit = strtolower($targetScholarship['benefit'] ?? '');
                                
                                foreach ($criteria['bidang'] as $b) {
                                    if (str_contains($bidang, $b) || str_contains($persyaratan, $b) || str_contains($benefit, $b)) {
                                        $matchedMajors[] = ucwords($b);
                                    }
                                }
                                
                                $this->currentIntent = 'validation_major';
                                if (!empty($matchedMajors)) {
                                    return $this->finalizeResponse("Iya benar, beasiswa **$nama** tersedia untuk jurusan **" . implode(', ', $matchedMajors) . "**. 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Mohon maaf, sepertinya beasiswa **$nama** tidak secara spesifik menyebutkan ketersediaan untuk jurusan **" . implode(', ', array_map('ucwords', $criteria['bidang'])) . "**. 😊", $normalizedData);
                                }
                            }

                            // 2. Validasi Jenjang
                            if (!empty($criteria['jenjang'])) {
                                $matchedLevels = [];
                                $rowJenjang = strtoupper($targetScholarship['jenjang'] ?? '');
                                foreach ($criteria['jenjang'] as $l) {
                                    if (str_contains($rowJenjang, $l)) {
                                        $matchedLevels[] = $l;
                                    }
                                }
                                
                                $this->currentIntent = 'validation_jenjang';
                                if (!empty($matchedLevels)) {
                                    return $this->finalizeResponse("Iya benar, beasiswa **$nama** tersedia untuk jenjang **" . implode('/', $matchedLevels) . "**. 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak tersedia untuk jenjang " . implode('/', $criteria['jenjang']) . ". Jenjang yang tersedia adalah **" . ($targetScholarship['jenjang'] ?? '-') . "**. 😊", $normalizedData);
                                }
                            }

                            // 3. Validasi Negara
                            if (!empty($criteria['negara'])) {
                                $matchedCountries = [];
                                $rowNegara = strtolower($targetScholarship['negara'] ?? '');
                                foreach ($criteria['negara'] as $c) {
                                    if (preg_match('/\b' . preg_quote($c, '/') . '\b/i', $rowNegara)) {
                                        $matchedCountries[] = ucwords($c);
                                    }
                                }
                                
                                $this->currentIntent = 'validation_negara';
                                if (!empty($matchedCountries)) {
                                    return $this->finalizeResponse("Iya benar, beasiswa **$nama** berlokasi di **" . implode(', ', $matchedCountries) . "**. 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak berlokasi di " . implode(', ', array_map('ucwords', $criteria['negara'])) . ". Lokasi aslinya adalah di **" . ($targetScholarship['negara'] ?? '-') . "**. 😊", $normalizedData);
                                }
                            }

                            // 4. Validasi Benua
                            if (!empty($criteria['benua'])) {
                                $matchedContinents = [];
                                $rowBenua = strtolower($targetScholarship['benua'] ?? '');
                                foreach ($criteria['benua'] as $c) {
                                    $searchTerms = [$c];
                                    if ($c === 'amerika') $searchTerms[] = 'america';
                                    if ($c === 'eropa') $searchTerms[] = 'europe';
                                    if ($c === 'australia') $searchTerms[] = 'oceania';
                                    foreach ($searchTerms as $term) {
                                        if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $rowBenua)) {
                                            $matchedContinents[] = ucwords($c);
                                            break;
                                        }
                                    }
                                }
                                
                                $this->currentIntent = 'validation_benua';
                                if (!empty($matchedContinents)) {
                                    return $this->finalizeResponse("Iya benar, beasiswa **$nama** berlokasi di benua **" . implode(', ', $matchedContinents) . "**. 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Bukan, beasiswa **$nama** tidak berada di benua " . implode(', ', array_map('ucwords', $criteria['benua'])) . ". Benua aslinya adalah **" . ucwords($targetScholarship['benua'] ?? '-') . "**. 😊", $normalizedData);
                                }
                            }

                            // 5. Validasi Deadline (Bulan)
                            if (!empty($criteria['bulan'])) {
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

                                $deadlineStr = strtolower($targetScholarship['deadline'] ?? '');
                                $matchedMonths = [];
                                foreach ($criteria['bulan'] as $m_key) {
                                    if (isset($monthsConfig[$m_key])) {
                                        foreach ($monthsConfig[$m_key] as $v) {
                                            if (str_contains($deadlineStr, $v)) {
                                                $matchedMonths[] = ucwords($m_key);
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                $this->currentIntent = 'validation_deadline';
                                if (!empty($matchedMonths)) {
                                    return $this->finalizeResponse("Iya benar, deadline pendaftaran beasiswa **$nama** adalah pada bulan **" . implode(', ', $matchedMonths) . "** (Detail: " . ($targetScholarship['deadline'] ?? '-') . "). 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Bukan, deadline beasiswa **$nama** bukan di bulan " . implode(', ', array_map('ucwords', $criteria['bulan'])) . ". Deadline aslinya adalah **" . ($targetScholarship['deadline'] ?? '-') . "**. 😊", $normalizedData);
                                }
                            }

                            // 6. Validasi Funding
                            if (!empty($criteria['funding'])) {
                                $targetFund = strtolower($criteria['funding']);
                                $actualFund = strtolower($targetScholarship['kategori'] ?? '');
                                $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $message);
                                
                                if ($isEnglishQuery) {
                                    $actualDisplay = 'Partially Funded';
                                    if ((str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) && (str_contains($actualFund, 'partially') || str_contains($actualFund, 'sebagian') || str_contains($actualFund, 'partial'))) {
                                        $actualDisplay = 'Fully & Partially Funded';
                                    } elseif (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) {
                                        $actualDisplay = 'Fully Funded';
                                    }
                                    $targetDisplay = ($targetFund === 'fully funded') ? 'Fully Funded' : 'Partially Funded';
                                } else {
                                    $actualDisplay = 'Pendanaan Sebagian (Parsial)';
                                    if ((str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) && (str_contains($actualFund, 'partially') || str_contains($actualFund, 'sebagian') || str_contains($actualFund, 'partial'))) {
                                        $actualDisplay = 'Pendanaan Penuh & Sebagian';
                                    } elseif (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) {
                                        $actualDisplay = 'Pendanaan Penuh (Full Gratis)';
                                    }
                                    $targetDisplay = ($targetFund === 'fully funded') ? 'Pendanaan Penuh' : 'Pendanaan Sebagian';
                                }

                                $isMatched = false;
                                if ($targetFund === 'fully funded') {
                                    if (str_contains($actualFund, 'fully') || str_contains($actualFund, 'penuh')) $isMatched = true;
                                } else {
                                    if (str_contains($actualFund, 'partially') || str_contains($actualFund, 'sebagian') || str_contains($actualFund, 'partial')) $isMatched = true;
                                }

                                $this->currentIntent = 'validation_funding';
                                if ($isMatched) {
                                    return $this->finalizeResponse("Iya benar, beasiswa **$nama** kategorinya adalah **$actualDisplay**. 😊", $normalizedData);
                                } else {
                                    return $this->finalizeResponse("Bukan, beasiswa **$nama** kategorinya adalah **$actualDisplay**, bukan $targetDisplay. 😊", $normalizedData);
                                }
                            }
                        }
                    }
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
            $isNextPage = preg_match('/\b(lainnya|yang\s+lain|selanjutnya|berikutnya|next)\b/i', $message) || preg_match('/\b(tampilkan\s+lagi|lagi\s+dong|ada\s+lagi|masih\s+ada|tampilkan\s+yang\s+lain)\b/i', $message);
            if ($isNextPage && session()->has('last_search_all_results')) {
                $criteria = $this->extractCriteria($message);
                $lastCriteria = session()->get('last_search_criteria', []);
                $isSameCountry = empty($criteria['negara']) || $criteria['negara'] === ($lastCriteria['negara'] ?? []);
                
                if ($isSameCountry) {
                    $this->currentIntent = 'next_page';
                    return $this->handleNextPage($normalizedData);
                }
            }

            // 4. PERTANYAAN UMUM (FAQ / Pengertian) - DIPRIORITASKAN DI ATAS SEARCH
            $faqAnswer = $this->handleFAQ($message);
            if ($faqAnswer) {
                $this->currentIntent = 'faq';
                return $this->finalizeResponse($faqAnswer, $normalizedData);
            }

            // 6. SEARCH & FILTER (Hanya jika RAG aktif)
            if ($ragEnabled && $this->isSearchQuery($message)) {
                $this->currentIntent = 'search';
                return $this->handleSearch($rawMessage, $message, $normalizedData, $forcedLocation);
            }

            // 1.8 TOPIC GUARD (Penyaring Topik Ketat untuk Sidang)
            $isGreeting = preg_match('/\b(ha+i+|hi+|ha+lo+|ha+llo+|he+lo+|he+llo+|pagi+|siang+|sore+|malam+|tanya+|nanya+|makasih+|thanks+|thank you|mks+|pilih|nomor|no|nmr|#|yang lain|selanjutnya|berikutnya)\b/i', $message);
            $isSearch = $this->isSearchQuery($message);
            $isDetail = ($detailIntent !== null);
            $isAck = $this->isAcknowledgment($message);
            $isFAQ = ($this->handleFAQ($message) !== null);
            
            // LOGIKA UTAMA: Jika RAG Aktif, WAJIB masuk salah satu kategori di atas. 
            // Jika tidak (pertanyaan random), TOLAK LANGSUNG.
            if ($ragEnabled && !$isGreeting && !$isSearch && !$isDetail && !$isAck && !$isFAQ) {
                $this->currentIntent = 'out_of_topic';
                return $this->finalizeResponse($this->getOutOfTopicResponse(), $normalizedData);
            }





            // 1.10 SEARCH HANDLER
            if ($ragEnabled && $isSearch) {
                return $this->handleSearch($rawMessage, $message, $normalizedData);
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
        // Hapus tanda baca umum agar tidak mengotori kata
        $text = preg_replace('/[?!.,\/#$%\^&\*;:{}=\-_`~()]/', ' ', $text);
        
        $text = strtolower(trim($text));
        $hasTypo = false;
        $typoWord = '';
        $correctedWord = '';

        // Hapus akhiran '-nya' dan 'nya' (baik bergabung maupun terpisah) agar tidak mengotori kata dasar
        // Proteksi kata dasar asli bahasa Indonesia + kata paginasi (selanjutnya/berikutnya/lainnya)
        // agar tidak rusak jadi "selanjut/berikut/lain" yang gagal terdeteksi sebagai "halaman berikutnya".
        $text = preg_replace('/\b(?!tanya\b|nanya\b|hanya\b|punya\b|nyonya\b|selanjutnya\b|berikutnya\b|lainnya\b|sebelumnya\b)(\w+)(?:-nya|nya)\b/i', '$1', $text);
        $text = preg_replace('/\bnya\b/i', '', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

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
            'design' => 'desain', 'engineering' => 'teknik', 'medicine' => 'kedokteran', 'medical' => 'kedokteran',
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
            'kpn' => 'kapan', 'dmn' => 'dimana', 'spy' => 'supaya', 'utk' => 'untuk',
            'sy' => 'saya', 'km' => 'kamu', 'sm' => 'sama', 'bgt' => 'banget', 'bs' => 'bisa', 'tdk' => 'tidak',
            'nmr' => 'nomor', 'no' => 'nomor', 'brp' => 'berapa', 'blm' => 'belum', 'sdh' => 'sudah',
            'jg' => 'juga', 'jd' => 'jadi', 'dr' => 'dari', 'lg' => 'lagi', 'skrg' => 'sekarang',
            'pake' => 'pakai', 'pk' => 'pakai', 'tlg' => 'tolong', 'tlng' => 'tolong', 'lgsg' => 'langsung',
            'knp' => 'kenapa', 'bgmn' => 'bagaimana', 'ad' => 'ada', 'buat' => 'untuk',
            'cr' => 'cara', 'dftr' => 'daftar', 'pndftrn' => 'pendaftaran',
            'pengen' => 'mau', 'pingin' => 'mau', 'pen' => 'mau', 'syrt' => 'persyaratan',
            'gimana caranya' => 'apply', 'cara daftarnya' => 'apply', 'cara pendaftarannya' => 'apply', 'caranya' => 'apply', 'aps' => 'apa',
            'infonya' => 'detail', 'liat' => 'detail', 'lihat' => 'detail', 'info' => 'detail', 'detail' => 'detail', 'selengkapnya' => 'detail', 'detailnya' => 'detail',
            'syaratnya' => 'persyaratan', 'benefitnya' => 'benefit', 'deadlinenya' => 'deadline', 'dl' => 'deadline', 'dlnya' => 'deadline', 'dl nya' => 'deadline',
            'kriteria' => 'persyaratan', 'kriterianya' => 'persyaratan', 'dokumen' => 'persyaratan', 'dokumennya' => 'persyaratan', 'berkas' => 'persyaratan', 'berkasnya' => 'persyaratan', 'eligibility' => 'persyaratan', 'qualification' => 'persyaratan', 'ketentuan' => 'persyaratan', 'ketentuannya' => 'persyaratan',
            'cara pendaftaran' => 'apply',
            'bole' => 'boleh', 'bisakah' => 'boleh',
            'ada ga' => 'ada', 'ada gak' => 'ada', 'ada g' => 'ada', 'adakah' => 'ada', 'ada kah' => 'ada',
            'dapet' => 'dapat', 'nyari' => 'cari', 'cariin' => 'cari',
            'lu' => 'kamu', 'lo' => 'kamu', 'loe' => 'kamu', 'ente' => 'kamu',
            'gw' => 'saya', 'gue' => 'saya', 'gua' => 'saya', 'aku' => 'saya',
            'apa aja' => 'apa', 'apa saja' => 'apa', 'apanya' => 'apa',
            'thn' => 'tahun',
            'deket' => 'dekat', 'dkt' => 'dekat', 'terdeket' => 'terdekat', 'terdkt' => 'terdekat',
            'masi' => 'masih'
        ];
        foreach ($synonyms as $key => $value) {
            // Gunakan preg_replace dengan word boundary agar tidak merusak kata lain 
            // (misal: 'no' tidak merubah 'nomor' menjadi 'nomormor')
            $text = preg_replace('/\b' . preg_quote($key, '/') . '\b/i', $value, $text);
        }

        // 2. Typo Tolerance dengan Algoritma Levenshtein (Sangat Pintar)
        // Mengecek kemiripan kata untuk mentoleransi typo
        $targetWords = [
            'halo', 'hallo', 'hai', 'hello', 'hi', 'helo',
            'benefit', 'benefitnya', 'persyaratan', 'syaratnya', 'syarat', 'deadline', 'deadlinenya', 
            'apply', 'daftar', 'daftarnya', 'pendaftaran', 'beasiswa', 'beasiswanya', 'pendanaan',
            'detail', 'boleh', 'terimakasih', 'makasih', 'negara', 'benua', 'kapan', 'gimana', 'dimana', 'bagaimana', 'cara',
            'apa', 'aja', 'yang', 'dong', 'dekat', 'luar', 'dalam', 'negeri',
            'indonesia', 'inggris', 'jerman', 'jepang', 'korea', 'china', 'taiwan', 'australia', 'turki', 'swiss', 'belanda', 'singapura', 'malaysia', 'thailand', 'arab', 'saudi', 'eropa', 'asia', 'amerika', 'afrika',
            'matematika', 'statistika', 'fisika', 'kimia', 'biologi', 'kedokteran', 'farmasi', 'teknik', 'arsitektur', 'komputer', 'informatika', 'hukum', 'ekonomi', 'akuntansi', 'manajemen', 'bisnis', 'psikologi', 'pertanian', 'kehutanan', 'perikanan', 'peternakan', 'seni', 'desain', 'komunikasi', 'sastra', 'pendidikan', 'politik', 'sejarah', 'filsafat', 'sosiologi',
            'sarjana', 'magister', 'doktor', 'diploma', 'kuliah',
            // Nama bulan SENGAJA tidak dimasukkan sebagai target koreksi: dulu menyebabkan
            // "full"/"fuli" terkoreksi jadi "juli". Typo bulan sudah ditangani di extractCriteria.
            'kasih', 'tolong', 'tampilkan', 'carikan', 'minta', 'ingin', 'saja', 'buka', 'tutup', 'aktif', 'lewat', 'belum', 'masih'
        ];
        $words = explode(' ', $text);
        foreach ($words as &$word) {
            // Bersihkan tanda baca dari kata untuk perbandingan Levenshtein yang akurat
            $cleanWord = preg_replace('/[^\w]/', '', $word);
            
            // JANGAN PERNAH mengoreksi kata dasar umum agar tidak salah koreksi
            if (in_array($cleanWord, ['arti', 'itu', 'loa', 'toefl', 'ielts', 'tanpa', 'lpdp', 'mext', 'erasmus', 'kip', 'selamat', 'nasi', 'goreng', 'resep', 'harga', 'emas', 'pagi', 'siang', 'sore', 'malam', 'mantap', 'paham', 'kalau', 'masih', 'kasih', 'negeri', 'belum', 'lewat', 'buka', 'tutup', 'ada', 'apa', 'saja', 'yang', 'hari', 'ini', 'dan', 'atau', 'dari', 'untuk', 'utara', 'selatan', 'timur', 'barat', 'ngga', 'nggak', 'gak', 'ga', 'bulan', 'tahun', 'tanggal',
                // Whitelist kata kunci domain agar TIDAK dikoreksi salah oleh Levenshtein
                // (mencegah full->juli, penuh->benua, jenjang->jepang, saya->saja, dll)
                'saya', 'bisa', 'full', 'fully', 'funded', 'penuh', 'jenjang', 'biaya', 'dana', 'gratis', 'warna', 'mau']) || in_array($cleanWord, $targetWords)) {
                continue;
            }
            
            // Periksa kata dengan panjang >= 3
            if (strlen($cleanWord) >= 3) {
                $bestTarget = null;
                $minDist = 999;
                
                foreach ($targetWords as $target) {
                    // Hanya toleransi 1 perubahan huruf. Ambang 2 dulu menyebabkan korupsi
                    // kata kunci (full->juli, penuh->benua, jenjang->jepang). Typo embedding
                    // sudah menangani sisanya secara semantik.
                    $dist = levenshtein($cleanWord, $target);
                    if ($cleanWord !== $target && $dist > 0 && $dist <= 1) {

                        if ($dist < $minDist) {
                            $minDist = $dist;
                            $bestTarget = $target;
                        }
                    }
                }
                
                if ($bestTarget !== null) {
                    $hasTypo = true;
                    $typoWord = $word;
                    $correctedWord = $bestTarget;
                    $word = $bestTarget; // Tetap dikoreksi di internal text
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

    private function isSearchQuery($message)
    {
        // 1. Jika mengandung kata kunci pencarian umum atau beasiswa
        $mustHave = [
            'beasiswa', 'scholarship', 'kuliah', 'studi', 'daftar', 'apply', 'registrasi', 
            'pendaftaran', 's1', 's2', 's3', 'd3', 'd4', 'jenjang', 'sarjana', 'magister', 'doktor',
            'cari', 'carikan', 'temukan', 'info', 'informasi', 'rekomendasi', 'tampilkan', 'ada'
        ];
        
        foreach ($mustHave as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $message)) {
                // Pengecualian: 'mata kuliah' bukan beasiswa
                if ($kw === 'kuliah' && preg_match('/\bmata\s+kuliah\b/i', $message) && !str_contains($message, 'beasiswa')) {
                    continue;
                }
                return true;
            }
        }

        // 2. Jika ada angka tahun (4 digit)
        if (preg_match('/\b20[0-9]{2}\b/', $message)) {
            return true;
        }

        // 3. Jika berhasil mengekstrak kriteria spesifik apa pun (seperti negara, benua, jenjang, bidang, funding, dll)
        $criteria = $this->extractCriteria($message);
        if (!empty($criteria['negara']) || 
            !empty($criteria['benua']) || 
            !empty($criteria['jenjang']) || 
            !empty($criteria['bulan']) || 
            !empty($criteria['bidang']) || 
            !empty($criteria['funding']) || 
            !empty($criteria['lokasi_tipe'])) {
            return true;
        }

        return false;
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
        // Kata yang SANGAT tidak relevan — selalu blokir (tidak mungkin jadi jurusan/bidang).
        $hardForbidden = [
            'resep', 'minum', 'tidur', 'politik', 'agama', 'sholat', 'doa', 'game',
            'shopee', 'tokopedia', 'tiktok', 'cuaca', 'judi', 'slot', 'hack', 'bobol',
            'coding', 'pacar', 'menikah', 'sedih', 'happy', 'senang', 'bahagia', 'kecewa', 'bingung', 'bimbang',
            'nonton', 'bioskop', 'loker', 'lowongan', 'cpns', 'pns', 'gaji', 'harga',
            'laptop', 'hp', 'handphone', 'iphone', 'android', 'berita', 'presiden', 'menteri',
            'mata kuliah', 'materi kuliah', 'materi pelajaran', 'tugas kuliah', 'ujian', 'skripsi',
            'algoritma', 'pengolahan paralel', 'struktur data', 'basis data', 'jaringan komputer'
        ];
        foreach ($hardForbidden as $bad) {
            if (str_contains($message, $bad)) return true;
        }

        // Kata yang BISA jadi nama jurusan/bidang (musik, kuliner, film, dll).
        // Hanya blokir jika TIDAK ada konteks beasiswa, agar "beasiswa jurusan musik" lolos.
        $softForbidden = ['musik', 'lagu', 'film', 'masak', 'makan', 'minuman', 'bola'];
        $hasScholarshipContext = (bool)preg_match('/\b(beasiswa|scholarship|jurusan|prodi|kuliah|studi|s1|s2|s3|d3|d4|magister|sarjana|doktor)\b/i', $message);
        if (!$hasScholarshipContext) {
            foreach ($softForbidden as $bad) {
                if (str_contains($message, $bad)) return true;
            }
        }

        return false;
    }

    private function handleFAQ($message)
    {
        $m = strtolower($message);

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
        if (str_contains($m, 'tanpa toefl') || str_contains($m, 'tanpa ielts')) {
            return "Ada beberapa beasiswa yang tidak mewajibkan TOEFL/IELTS, seperti beasiswa Turkiye Burslari (Turki), GKS (Korea - jalur tertentu), atau beasiswa Pemerintah Rusia. Beberapa beasiswa dalam negeri juga banyak yang tidak memerlukan sertifikat bahasa Inggris. Mau saya carikan yang spesifik? 😊";
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
        if (preg_match('/\b(apakah|ada|adakah|bagaimana|apa|kapan|tanya)\b/i', $m) && (str_contains($m, 'sma') || str_contains($m, 'smk') || str_contains($m, 'sekolah'))) {
            return "Ada beberapa beasiswa untuk anak SMA/SMK sederajat, baik bantuan dari pemerintah seperti KIP/PIP, maupun beasiswa swasta/yayasan untuk melanjutkan kuliah S1, seperti Beasiswa Djarum, Beasiswa Tanoto, dll. 😊";
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

        if (str_contains($m, 'ielts') || str_contains($m, 'toefl')) {
            return "IELTS (International English Language Testing System) dan TOEFL (Test of English as a Foreign Language) adalah tes standar internasional untuk mengukur kemampuan bahasa Inggris yang sering menjadi syarat utama pendaftaran beasiswa luar negeri.";
        }
        if (str_contains($m, 'loa')) {
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

        if (count($allResults) > $startIndex + 5) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau silakan pilih nomor beasiswa untuk melihat **detail**.";
        } else {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function handleSearch($rawText, $message, $normalizedData, $forcedLocation = null)
    {
        // Pengecekan Khusus: "berikan data beasiswa [tahun]" atau variasi kalimat lain yang meminta beasiswa tahun tersebut secara umum
        $hasYearPattern = preg_match('/\b(20[2-3][0-9])\b/', $message, $matches);
        if ($hasYearPattern) {
            $year = $matches[1];
            // Deteksi jika kueri mengandung kata kunci umum pencarian daftar
            $isGeneralListRequest = preg_match('/\b(data|list|daftar|semua|tampilkan|berikan|print|show|kumpulan|database|seluruh)\b/i', $message);
            
            // Periksa kriteria lain untuk memastikan kueri ini bersifat umum (tidak menyebutkan negara, bidang, dll)
            $tempCriteria = $this->extractCriteria($message);
            $hasSpecificFilters = !empty($tempCriteria['negara']) || !empty($tempCriteria['benua']) || !empty($tempCriteria['jenjang']) || !empty($tempCriteria['bidang']) || !empty($tempCriteria['lokasi_tipe']) || !empty($tempCriteria['funding']);
            
            if ($isGeneralListRequest || !$hasSpecificFilters) {
                $allowedYears = ['2024', '2025', '2026', '2027'];
                if (!in_array($year, $allowedYears)) {
                    return $this->finalizeResponse("Mohon maaf, **ScholarBot** hanya menyediakan data untuk tahun **2024**, **2025**, **2026**, dan **2027** saat ini, terima kasih 😊", $normalizedData);
                }
                
                $all = DB::table('scholarships')
                    ->where(function($q) use ($year) {
                        $q->where('deadline', 'like', "%{$year}%")
                          ->orWhere('nama_beasiswa', 'like', "%{$year}%");
                    })
                    ->select(self::SCHOLARSHIP_COLUMNS)
                    ->get()
                    ->toArray();
                    
                shuffle($all);
                $all = array_slice($all, 0, 105);
                
                // Tampilkan 5 data pertama secara acak
                $limitedResults = array_slice($all, 0, 5);
                
                session()->put('last_search_all_results', $all);
                session()->put('last_search_page', 1);
                session()->put('last_search_results', $limitedResults);
                session()->forget('selected_scholarship');
                
                $countResult = count($all);
                $resp = "Berikut beasiswa tahun {$year} (menampilkan 5 dari {$countResult} data secara acak):\n\n";
                foreach ($limitedResults as $i => $s) {
                    $s = (array)$s;
                    $resp .= ($i + 1) . ". **{$s['nama_beasiswa']}** - " . ($s['negara'] ?? 'Luar Negeri') . " (" . ($s['jenjang'] ?? '-') . ")\n\n";
                }
                
                if ($countResult > 5) {
                    $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya, atau ketik nomor beasiswa (misal: **pilih no 3**) untuk melihat **detail**.";
                } else {
                    $resp .= "Silakan ketik nomor beasiswa (misal: **pilih no 3**) untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
                }
                
                $this->currentIntent = 'search';
                return $this->finalizeResponse($resp, $normalizedData);
            }
        }

        $criteria = $this->extractCriteria($message);
        if ($forcedLocation) $criteria['lokasi_tipe'] = $forcedLocation;
        if ($forcedLocation === 'dalam' && empty($criteria['negara'])) $criteria['negara'][] = 'indonesia';

        // Pengecekan Tahun (Poin Tambahan)
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
            
            // JIKA user menyebutkan kriteria baru yang kuat (Negara/Benua/Jurusan/Tahun/Tipe Lokasi), 
            // atau melakukan pencarian baru secara eksplisit (seperti mengandung kata "cari/nyari"),
            // maka kita RESET kriteria lama agar tidak campur aduk.
            $isExplicitNewSearch = preg_match('/\b(cari|carikan|nyari|temukan|mencari|tampilkan|berikan|list|semua)\b/i', $message) 
                || (str_contains($message, 'beasiswa') && strlen($message) > 25);

            $hasNewStrongCriteria = !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['bidang']) || !empty($criteria['tahun']) || !empty($criteria['lokasi_tipe']) || $isExplicitNewSearch;
            
            if (!$hasNewStrongCriteria) {
                // Gunakan memori lama jika tidak ada kriteria baru
                if (empty($criteria['negara']) && !empty($lastCriteria['negara'])) {
                    $criteria['negara'] = $lastCriteria['negara'];
                }
                if (empty($criteria['benua']) && !empty($lastCriteria['benua'])) {
                    $criteria['benua'] = $lastCriteria['benua'];
                }
                if (empty($criteria['lokasi_tipe']) && !empty($lastCriteria['lokasi_tipe'])) {
                    $criteria['lokasi_tipe'] = $lastCriteria['lokasi_tipe'];
                }
                if (empty($criteria['jenjang']) && !empty($lastCriteria['jenjang'])) {
                    $criteria['jenjang'] = $lastCriteria['jenjang'];
                }
                if (empty($criteria['bidang']) && !empty($lastCriteria['bidang'])) {
                    $criteria['bidang'] = $lastCriteria['bidang'];
                }
                if (empty($criteria['funding']) && !empty($lastCriteria['funding'])) {
                    $criteria['funding'] = $lastCriteria['funding'];
                }
            }
        }

        // Clean searchQuery from stop words/filter words to improve embedding/hybrid search matching
        $stopWords = [
            'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'di', 'untuk', 'ke',
            'nya', 'aja', 'lah', 'kok', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong',
            'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'siapa', 'bagaimana', 'gimana'
        ];
        
        $searchQueryClean = $message;
        foreach ($stopWords as $sw) {
            $searchQueryClean = preg_replace('/\b' . preg_quote($sw, '/') . '\b/i', '', $searchQueryClean);
        }
        $searchQueryClean = preg_replace('/\s+/', ' ', trim($searchQueryClean));
        
        $cleanWords = explode(' ', $searchQueryClean);
        $cleanWords = array_filter($cleanWords, fn($w) => strlen($w) >= 3 && !preg_match('/^\d{4}$/', $w) && !in_array($w, ['tahun', 'thn', 'beasiswa', 'scholarship', 'kuliah', 'studi', 'luar', 'dalam', 'negeri', 'indonesia', 'nya', 'aja', 'lah', 'kok', 'sih', 'deh', 'tuh', 'yah', 'kan', 'kasih', 'kasi', 'tau', 'tahu', 'tolong', 'bagi', 'minta', 'info', 'data', 'list', 'kumpulan', 'tampilkan', 'carikan', 'nyari', 'mau', 'boleh', 'ingin', 'tanya', 'nanya', 'bertanya', 'masih', 'buka', 'tutup', 'aktif', 'terbuka', 'sekarang', 'saat', 'apa', 'aja', 'yg', 'yang', 'kalau', 'dn', 'ln', 'sarjana', 'magister', 'doktor', 'master', 'postgraduate', 'phd', 'doctor', 'doctoral', 'diploma']));
        if (!empty($cleanWords)) {
            $criteria['clean_subject_words'] = array_values($cleanWords);
        }

        // If the cleaned query is empty or too short, fallback to the normalized message
        if (strlen($searchQueryClean) > 3) {
            $searchQuery = $searchQueryClean;
        } else {
            $searchQuery = $message;
        }

        if (strlen($searchQuery) < 15 && !empty($criteria['negara'])) {
            $searchQuery = "beasiswa " . implode(' ', $criteria['negara']);
        }

        if (preg_match('/\b(paling dekat|deadline dekat|mepet|terdekat|tercepat)\b/i', $message)) {
            $criteria['sort_deadline'] = true;
            $criteria['sort_deadline_dir'] = 'asc';
        } elseif (preg_match('/\b(terjauh|terlama|paling lama|paling jauh)\b/i', $message)) {
            $criteria['sort_deadline'] = true;
            $criteria['sort_deadline_dir'] = 'desc';
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
            // Ambil data lengkap dengan cara yang lebih ringan
            $rawResults = DB::table('scholarships')
                ->whereIn('id', $ids)
                ->select(self::SCHOLARSHIP_COLUMNS)
                ->get()
                ->all();
            
            // Urutkan kembali di level PHP
            $idMap = array_flip($ids);
            usort($rawResults, function($a, $b) use ($idMap, $criteria) {
                 // JIKA USER MINTA DEADLINE TERDEKAT/TERJAUH
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

        // VARIASI DATA: Jika pencarian tidak menyebutkan negara spesifik (misal mencari benua saja atau pencarian umum), mix datanya agar seimbang
        if (empty($criteria['negara']) && empty($criteria['sort_deadline'])) {
            shuffle($filtered);
        }

        if (empty($filtered)) {
            if (!empty($criteria['negara']) || !empty($criteria['mentioned_location'])) {
                return $this->finalizeResponse("Mohon maaf, saya belum memiliki data beasiswa untuk lokasi/negara tersebut. 😊", $normalizedData);
            }
            if (!empty($criteria['mentioned_target_group'])) {
                return $this->finalizeResponse("Mohon maaf, saya belum memiliki data beasiswa untuk target/kategori sasaran tersebut. 😊", $normalizedData);
            }
            return $this->finalizeResponse("Mohon maaf, saya tidak menemukan beasiswa yang sesuai dengan pencarian tersebut. 😊", $normalizedData);
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
            
            // Build the dynamic attributes list
            $attrs = [];
            
            // 1. Lokasi / Negara (Tampilkan jika ada keyword lokasi/negara/benua/ln/dn atau kriteria lokasi ada)
            $hasLocKeyword = preg_match('/\b(negara|lokasi|tempat|benua|ln|dn|luar|dalam|di|dari|indonesia|inggris|jepang|jerman|swiss|usa|as|korea|turki|arab)\b/i', $message) 
                || !empty($criteria['negara']) || !empty($criteria['benua']) || !empty($criteria['lokasi_tipe']);
            
            // 2. Jenjang (Tampilkan jika ada keyword jenjang atau kriteria jenjang ada)
            $hasLevelKeyword = preg_match('/\b(jenjang|tingkat|s1|s2|s3|d3|d4|sarjana|magister|doktor|diploma)\b/i', $message) 
                || !empty($criteria['jenjang']);
            
            // 3. Kategori / Funding (Tampilkan jika ada keyword funding/gratis atau kriteria funding ada)
            $hasFundingKeyword = preg_match('/\b(fully|partially|partial|fund|gratis|biaya|dana|saku|tunjangan|kategori)\b/i', $message) 
                || !empty($criteria['funding']) || $fallbackToOtherFunding;
            
            // 4. Deadline (Tampilkan jika ada keyword deadline/dl/buka atau kriteria deadline/bulan/still_open ada)
            $hasDeadlineKeyword = preg_match('/\b(deadline|dl|tanggal|bulan|kapan|tutup|batas|buka|aktif|sekarang)\b/i', $message) 
                || !empty($criteria['bulan']) || !empty($criteria['sort_deadline']) || !empty($criteria['still_open']);
            
            // Determine defaults if nothing specific requested
            if (!$hasLocKeyword && !$hasLevelKeyword && !$hasFundingKeyword && !$hasDeadlineKeyword) {
                // Default fallback: show country and level
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
            if ($hasDeadlineKeyword) {
                $resp .= " - " . ($s['deadline'] ?? '-');
            }
            $resp .= "\n\n";
        }

        if (count($filtered) > 5) {
            $resp .= "Masih ada beasiswa lainnya. Ketik **'yang lain'** untuk melihat daftar selanjutnya.";
        } elseif ($count > 0) {
            $resp .= "Silakan pilih nomor beasiswa untuk melihat **detail** seperti **benefit**, **syarat**, **deadline**, atau **cara daftar**.";
        }
        
        return $this->finalizeResponse($resp, $normalizedData);
    }

    private function extractCriteria($text)
    {
        // #6: memoize per-request (dipanggil berulang oleh isSearchQuery, topic guard, handleSearch).
        if (isset($this->criteriaCache[$text])) {
            return $this->criteriaCache[$text];
        }

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

        // Deteksi Jurusan secara dinamis (mendukung jurusan apapun setelah kata kunci 'jurusan'/'prodi')
        if (preg_match('/\b(jurusan|prodi|bidang|studi|program studi)\s+([a-z\s]+)/i', $text, $matches)) {
            $potentialMajor = trim($matches[2]);
            $words = explode(' ', $potentialMajor);
            $potentialMajor = implode(' ', array_slice($words, 0, 2));
            $nonMajors = ['ini', 'itu', 'tersebut', 'yang', 'di', 'pada', 'luar', 'dalam', 'negeri', 'tahun', 'untuk'];
            $potentialMajorClean = trim(str_replace($nonMajors, '', $potentialMajor));
            if (!empty($potentialMajorClean) && strlen($potentialMajorClean) > 2) {
                $c['bidang'][] = strtolower($potentialMajorClean);
            }
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
        $c['bidang'] = array_unique($c['bidang']);

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
        // #6: daftar negara tidak bergantung pada $text, cukup di-query sekali per request.
        if ($this->allCountriesCache === null) {
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
            $this->allCountriesCache = $allCountries;
        }
        $allCountries = $this->allCountriesCache;

        foreach ($allCountries as $country) {
            if (preg_match('/\b' . preg_quote($country, '/') . '\b/i', $text)) {
                // Jangan masukkan jika user menuliskan "benua [nama_negara/benua]"
                if (preg_match('/\bbenua\s+' . preg_quote($country, '/') . '\b/i', $text)) {
                    continue;
                }
                $c['negara'][] = $country;
                if (empty($c['negara_ori'])) $c['negara_ori'] = ucwords($country);
            }
        }
        $c['negara'] = array_unique($c['negara']);

        // 3. Deteksi Benua
        $continentsMapping = [
            'eropa' => '/\b(eropa|europe)\b/i',
            'asia' => '/\b(asia)\b/i',
            'australia' => '/\b(australia|oseania|oceania)\b/i',
            'afrika' => '/\b(afrika|africa)\b/i',
            'amerika' => '/\b(amerik|americ)[a-z]*\b/i'
        ];
        foreach ($continentsMapping as $conKey => $pattern) {
            if (preg_match($pattern, $text)) {
                $c['benua'][] = $conKey;
            }
        }

        // 4. Deteksi Tipe Lokasi (Luar/Dalam Negeri)
        $lowerText = strtolower($text);
        if (preg_match('/\b(luar\s*negeri|international|abroad|luar|ln)\b/i', $lowerText)) {
            $c['lokasi_tipe'] = 'luar';
        } elseif (preg_match('/\b(dalam\s*negeri|domestic|local|indonesia|indo|dn)\b/i', $lowerText)) {
            $c['lokasi_tipe'] = 'dalam';
            $c['negara'][] = 'indonesia'; // Paksa tambah indonesia agar filter akurat
        }

        // 5. Deteksi Jenjang, Bulan, dan Funding
        if (preg_match('/\b(bulan ini)\b/i', $lowerText)) {
            $currentMonth = strtolower(now()->translatedFormat('F')); 
            $c['bulan'][] = $currentMonth;
        } elseif (preg_match('/\b(bulan depan)\b/i', $lowerText)) {
            $nextMonth = strtolower(now()->addMonth()->translatedFormat('F'));
            $c['bulan'][] = $nextMonth;
        }

        if (preg_match('/\b(masih buka|belum tutup|belum lewat|aktif|terbuka|saat ini|sekarang)\b/i', $lowerText)) {
            $c['still_open'] = true;
        }

        $levelMapping = [
            'pascasarjana' => 'S2', 'pasca sarjana' => 'S2',
            's1' => 'S1', 'sarjana' => 'S1', 'bachelor' => 'S1',
            's2' => 'S2', 'magister' => 'S2', 'master' => 'S2', 'postgraduate' => 'S2',
            's3' => 'S3', 'doktor' => 'S3', 'phd' => 'S3', 'doctor' => 'S3', 'doctoral' => 'S3',
            'd3' => 'D3', 'diploma 3' => 'D3',
            'd4' => 'D4', 'diploma 4' => 'D4'
        ];
        foreach ($levelMapping as $key => $val) {
            // Hanya cocok dengan word-boundary (BUKAN str_contains) agar "pascasarjana"
            // tidak salah terdeteksi S1 karena mengandung substring "sarjana".
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/i', $text)) {
                $c['jenjang'][] = $val;
            }
        }
        $c['jenjang'] = array_unique($c['jenjang']);
        
        $months = [
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
        foreach ($months as $m_key => $variants) {
            foreach ($variants as $v) {
                if (preg_match('/\b' . preg_quote($v, '/') . '\b/i', $text)) {
                    $c['bulan'][] = $m_key;
                    break;
                }
            }
        } 
        
        // PENDANAAN
        if (preg_match('/\b(gratis full|full gratis|gratis\w*|dana penuh|fully funded|biaya penuh|beasiswa full|full scholarship|full funded|gratis total|full pendanaan|pendanaan full|beasiswa penuh|menanggung seluruh|ditanggung penuh|membiayai penuh|seluruh biaya|biaya penuh|penuh|fully|full|fuly|fuli)\b/i', $text)) {
            $c['funding'] = 'Fully Funded';
        } elseif (preg_match('/\b(ukt doang|sebagian|partially funded|biaya sebagian|parsial|partial funded|beasiswa parsial|pendanaan sebagian|bantuan sebagian|partially|partial)\b/i', $text)) {
            $c['funding'] = 'Partially Funded';
        }

        // KEYWORD KHUSUS
        if (preg_match('/\b(tanpa toefl|tanpa ielts|no toefl|no ielts|tanpa sertifikat|tanpa tes bahasa|tanpa tofel|tanpa itels)\b/i', $text)) $c['no_test'] = true;
        if (preg_match('/\b(kurang mampu|miskin|ekonomi lemah|kip|tidak mampu|bantuan ukt|dhuafa|ekonomi rendah)\b/i', $text)) $c['ekonomi'] = true;
        if (preg_match('/\b(perempuan|wanita|khusus cewek|beasiswa cewek|khusus putri|khusus wanita)\b/i', $text)) $c['gender'] = 'perempuan';
        if (preg_match('/\b(fresh graduate|lulusan baru|baru lulus|freshgrad|fresh grad|baru wisuda)\b/i', $text)) $c['fresh_grad'] = true;
        if (preg_match('/\b(tanpa wawancara|no interview|tanpa interview|tanpa tes wawancara)\b/i', $text)) $c['no_interview'] = true;
        if (preg_match('/\b(gpa di bawah 3|ipk rendah|ipk di bawah 3|ipk kecil|gpa kecil|ipk di bawah)\b/i', $text)) $c['low_gpa'] = true;

        // Deteksi lokasi/negara/benua yang disebutkan oleh user setelah kata depan di/ke
        if (preg_match('/\b(?:di|ke|negara|lokasi|benua)\s+([a-zA-Z\s]{3,25})\b/i', $text, $locMatches)) {
            $mentionedLoc = strtolower(trim($locMatches[1]));
            // Hapus stopwords yang tidak merepresentasikan tempat
            $stopLocWords = ['dalam', 'luar', 'negeri', 'sini', 'sana', 'mana', 'atas', 'bawah', 'dekat', 'jauh', 'tahun', 'bulan', 'ekonomi', 'teknik', 'hukum', 'kedokteran', 'semua', 'jurusan', 'gratis', 'fully', 'funded', 'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'untuk', 'ke', 'di', 'ngga', 'nggak', 'gak', 'ga', 'tidak', 'bukan'];
            $mentionedLocClean = trim(preg_replace('/\b(' . implode('|', array_map('preg_quote', $stopLocWords)) . ')\b/i', '', $mentionedLoc));
            $mentionedLocClean = preg_replace('/\s+/', ' ', $mentionedLocClean);
            
            if (!empty($mentionedLocClean) && strlen($mentionedLocClean) > 2) {
                // Simpan mentioned_location untuk filter ketat
                $c['mentioned_location'] = $mentionedLocClean;
            }
        }

        // Deteksi kelompok target sasaran yang disebutkan setelah untuk/bagi/buat/khusus
        if (preg_match('/\b(?:untuk|bagi|buat|khusus)\s+([a-zA-Z\s]{3,20})\b/i', $text, $groupMatches)) {
            $mentionedGroup = strtolower(trim($groupMatches[1]));
            // Hapus stopwords yang umum
            $stopGroupWords = ['dalam', 'luar', 'negeri', 'sini', 'sana', 'mana', 'tahun', 'bulan', 'ekonomi', 'semua', 'jurusan', 'gratis', 'fully', 'funded', 'ada', 'apa', 'saja', 'yang', 'sih', 'dong', 'kah', 'ini', 'itu', 'tersebut', 'dari', 'untuk', 'di', 'ke', 'bagi', 'buat', 'khusus', 'kamu', 'saya', 'anda', 'kita', 'mereka'];
            $mentionedGroupClean = trim(preg_replace('/\b(' . implode('|', array_map('preg_quote', $stopGroupWords)) . ')\b/i', '', $mentionedGroup));
            $mentionedGroupClean = preg_replace('/\s+/', ' ', $mentionedGroupClean);
            
            // Periksa apakah kelompok tersebut adalah kriteria yang valid/umum
            $knownGroups = ['perempuan', 'wanita', 'putri', 'cewek', 'pria', 'laki', 'cowok', 'muslim', 'tahfidz', 'miskin', 'dhuafa', 'disabilitas', 'difabel', 'atlet', 'seni', 'prestasi', 'guru', 'dosen', 'pns', 'anak', 'yatim', 'piatu', 'alumni', 'karyawan', 'umum'];
            $knownLevels = ['s1', 's2', 's3', 'd3', 'd4', 'sma', 'smk', 'ma', 'mahasiswa', 'pelajar', 'siswa', 'gap year', 'gapyear'];
            
            $isKnown = false;
            foreach (array_merge($knownGroups, $knownLevels) as $kg) {
                if (str_contains($mentionedGroupClean, $kg)) {
                    $isKnown = true;
                    break;
                }
            }
            
            if (!empty($mentionedGroupClean) && strlen($mentionedGroupClean) > 2 && !$isKnown) {
                $c['mentioned_target_group'] = $mentionedGroupClean;
            }
        }

        $this->criteriaCache[$text] = $c;
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
                
                if ($target === 'partially funded') {
                    if (!str_contains($actual, 'partially') && !str_contains($actual, 'sebagian') && !str_contains($actual, 'partial')) return false;
                } else {
                    if (!str_contains($actual, 'fully') && !str_contains($actual, 'penuh')) return false;
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
                if (!$matchYear) return false;
            }

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

            // Filter Out Expired/Passed deadlines if sort_deadline or still_open is set
            if (!empty($criteria['sort_deadline']) || !empty($criteria['still_open'])) {
                $deadlineTime = $this->parseDeadlineDate($r->deadline ?? '');
                if ($deadlineTime !== null && $deadlineTime < time()) {
                    return false;
                }
            }

            // Filter Lokasi yang disebutkan secara spesifik (agar tidak bocor ke lokasi fiktif seperti Kutub Utara)
            if (!empty($criteria['mentioned_location'])) {
                $locPattern = strtolower($criteria['mentioned_location']);
                $searchArea = strtolower(($r->negara ?? '') . ' ' . ($r->benua ?? '') . ' ' . ($r->nama_beasiswa ?? '') . ' ' . ($r->deskripsi ?? ''));
                if (!preg_match('/\b' . preg_quote($locPattern, '/') . '\b/i', $searchArea)) {
                    return false;
                }
            }

            // Filter Kelompok Target yang disebutkan secara spesifik (agar tidak bocor ke target fiktif seperti Alien)
            if (!empty($criteria['mentioned_target_group'])) {
                $targetPattern = strtolower($criteria['mentioned_target_group']);
                $searchArea = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? '') . ' ' . ($r->jurusan ?? ''));
                if (!preg_match('/\b' . preg_quote($targetPattern, '/') . '\b/i', $searchArea)) {
                    return false;
                }
            }

            // Filter Relevansi Kata Kunci Inti (Core Subject Match)
            // Di-comment karena Vector Search / Hybrid Search sudah menangani relevansi pencarian.
            // Strict filter ini menyebabkan banyak query bahasa natural terbuang karena kata kunci tidak sama persis.
            /*
            if (!empty($criteria['clean_subject_words'])) {
                $hasWordMatch = false;
                $searchArea = strtolower(($r->nama_beasiswa ?? '') . ' ' . ($r->negara ?? '') . ' ' . ($r->benua ?? '') . ' ' . ($r->bidang ?? '') . ' ' . ($r->persyaratan ?? '') . ' ' . ($r->deskripsi ?? '') . ' ' . ($r->deadline ?? ''));
                
                foreach ($criteria['clean_subject_words'] as $word) {
                    if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $searchArea)) {
                        $hasWordMatch = true;
                        break;
                    }
                }
                if (!$hasWordMatch) {
                    return false;
                }
            }
            */

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
                $model = 'google/gemini-2.5-flash-lite';
            } elseif (str_starts_with($apiKey, 'AIza')) {
                // Google Gemini Direct API
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
                // Tambahkan context beasiswa ke prompt
                $context = $this->getScholarshipContext($message);
                
                // Jika context kosong / tidak ada data cocok di database
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
        
        $isEnglishQuery = preg_match('/\b(fully|partially|partial|fund)\b/i', $target);
        
        // Normalisasi tipe pendanaan
        if (str_contains($target, 'full') || str_contains($target, 'penuh')) {
            $targetType = 'fully funded';
            $displayType = $isEnglishQuery ? 'Fully Funded' : 'Pendanaan Penuh (Full Gratis)';
        } else {
            $targetType = 'partially funded';
            $displayType = $isEnglishQuery ? 'Partially Funded' : 'Pendanaan Sebagian (Parsial)';
        }

        // 1. CEK REFERENSI NOMOR
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

        // 2. CEK SELECTED SCHOLARSHIP (Konteks "ini/itu")
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

        // Cari semua pola tanggal: [1 atau 2 digit tanggal] [nama bulan] [4 digit tahun]
        // Contoh: "Gelombang I : 15 Maret 2026 / Gelombang II : 25 Juni 2026"
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

        // Jika tidak ada pola tanggal terdeteksi, coba parse seluruh string secara langsung
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

        // Pisahkan tanggal masa depan (aktif) dan masa lalu (lewat)
        $now = time();
        $futureTimes = array_filter($times, fn($t) => $t >= $now);

        if (!empty($futureTimes)) {
            // Jika ada gelombang masa depan, ambil yang terdekat (paling awal)
            return min($futureTimes);
        } else {
            // Jika semua gelombang sudah lewat, ambil yang paling terakhir (maksimal) untuk menandakan kapan ia benar-benar tutup
            return max($times);
        }
    }
}

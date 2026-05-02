<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/scholarship', function () {
    return view('scholarship');
})->name('scholarship');

Route::get('/scholarship/{id}', function ($id) {
    // Simulasi data beasiswa berdasarkan ID (mock data)
    $scholarship = [
        'id' => $id,
        'title' => 'LPDP Scholarship',
        'university' => 'Various Universities',
        'image' => '/images/scholarships/lpdp.jpg',
        'type' => 'domestic',
        'fullyFunded' => true,
        'country' => 'Indonesia',
        'deadline' => '2026-05-30',
        'degree' => 'Master & PhD',
        'description' => 'Beasiswa LPDP adalah program beasiswa yang dibiayai oleh pemerintah Indonesia melalui pemanfaatan Dana Pengembangan Pendidikan Nasional (DPPN).',
        'bidang' => ['Sains', 'Teknologi', 'Pendidikan', 'Sosial'],
        'jurusan' => ['Teknik Informatika', 'Manajemen', 'Pendidikan', 'Hukum'],
        'requirements' => [
            'Warga Negara Indonesia (WNI)',
            'Telah menyelesaikan studi program sarjana (S1/D4) atau magister (S2)',
            'Tidak sedang menempuh studi degree/non-degree',
            'Sertifikat kemampuan bahasa Inggris (IELTS/TOEFL)'
        ],
        'benefits' => [
            'Dana Pendaftaran',
            'Tuition Fee / Biaya SPP',
            'Tunjangan Hidup Bulanan',
            'Tiket Pesawat PP',
            'Asuransi Kesehatan'
        ]
    ];
    return view('scholarship-detail', compact('scholarship'));
})->name('scholarship.detail');

Route::get('/chatbot', function () {
    return view('chatbot');
})->name('chatbot');

Route::view('/bookmarks', 'bookmarks')->name('bookmarks');
Route::redirect('/saved', '/bookmarks');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::resource('users', UserController::class)->except(['show']);
    
    // Admin Scholarship Routes
    Route::resource('admin/scholarships', \App\Http\Controllers\AdminScholarshipController::class)->names('admin.scholarships');
    Route::post('admin/scholarships/sync-embeddings', [\App\Http\Controllers\AdminScholarshipController::class, 'syncEmbeddings'])->name('admin.scholarships.sync_embeddings');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

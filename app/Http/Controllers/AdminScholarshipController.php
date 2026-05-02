<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminScholarshipController extends Controller
{
    public function index()
    {
        // Nantinya ini akan mengambil data dari tabel scholarships
        $scholarships = collect([
            (object)[
                'id' => 1,
                'title' => 'LPDP Scholarship',
                'university' => 'Various Universities',
                'degree' => 'Master & PhD',
                'is_embedded' => true,
            ],
            (object)[
                'id' => 2,
                'title' => 'Chevening Scholarship',
                'university' => 'UK Universities',
                'degree' => 'Master',
                'is_embedded' => false,
            ]
        ]);
        
        return view('admin.scholarships.index', compact('scholarships'));
    }

    public function create()
    {
        return view('admin.scholarships.create');
    }

    public function store(Request $request)
    {
        // Simulasi simpan data
        return redirect()->route('admin.scholarships.index')->with('success', 'Data Beasiswa berhasil ditambahkan! Jangan lupa klik Sync Embeddings.');
    }

    public function edit($id)
    {
        // Simulasi cari data
        return view('admin.scholarships.edit', compact('id'));
    }

    public function update(Request $request, $id)
    {
        // Simulasi update data
        return redirect()->route('admin.scholarships.index')->with('success', 'Data Beasiswa berhasil diupdate!');
    }

    public function destroy($id)
    {
        // Simulasi hapus data
        return redirect()->route('admin.scholarships.index')->with('success', 'Data Beasiswa berhasil dihapus!');
    }

    public function syncEmbeddings(Request $request)
    {
        return back()->with('success', '🤖 Proses sinkronisasi Vector Embedding berhasil dijalankan!');
    }
}

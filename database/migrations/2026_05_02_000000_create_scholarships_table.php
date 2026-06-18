<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (!$isSqlite) {
            // 1. Pastikan ekstensi vector aktif di PostgreSQL
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        // 2. Buat tabel scholarships sesuai 13 kolom dataset
        Schema::create('scholarships', function (Blueprint $table) use ($isSqlite) {
            $table->id();
            $table->text('nama_beasiswa');     // Nama Beasiswa
            $table->text('benua')->nullable(); // Benua
            $table->text('negara')->nullable();   // Negara
            $table->text('jenjang')->nullable();     // Jenjang
            $table->text('deskripsi')->nullable(); // Deskripsi
            $table->text('deadline')->nullable();  // Deadline
            $table->text('kategori')->nullable();  // Kategori
            $table->text('jurusan')->nullable();       // Jurusan
            $table->text('benefit')->nullable();     // Benefit
            $table->text('persyaratan')->nullable();// Persyaratan
            $table->text('sumber')->nullable();    // Sumber
            $table->text('url')->nullable();       // URL
            $table->text('url_asli')->nullable(); // URL ASLI
            $table->timestamps();

            if ($isSqlite) {
                $table->text('embedding')->nullable();
                $table->text('fts_content')->nullable();
            }
        });

        if (!$isSqlite) {
            // 3. Tambahkan kolom embedding (vector 1536) & Full Text Search (tsvector)
            DB::statement('ALTER TABLE scholarships ADD COLUMN embedding vector(1536)');
            DB::statement('ALTER TABLE scholarships ADD COLUMN fts_content tsvector');

            // 4. Tambahkan index
            DB::statement('CREATE INDEX ON scholarships USING hnsw (embedding vector_cosine_ops)');
            DB::statement('CREATE INDEX scholarships_fts_idx ON scholarships USING gin (fts_content)');

            // 5. Trigger untuk otomatis update FTS
            DB::statement("
                CREATE OR REPLACE FUNCTION scholarships_update_fts() RETURNS trigger AS $$
                BEGIN
                  new.fts_content :=
                    setweight(to_tsvector('indonesian', coalesce(new.nama_beasiswa, '')), 'A') ||
                    setweight(to_tsvector('indonesian', coalesce(new.benua, '')), 'B') ||
                    setweight(to_tsvector('indonesian', coalesce(new.negara, '')), 'B') ||
                    setweight(to_tsvector('indonesian', coalesce(new.deskripsi, '')), 'C');
                  RETURN new;
                END
                $$ LANGUAGE plpgsql;
            ");

            DB::statement("
                CREATE TRIGGER scholarships_fts_update BEFORE INSERT OR UPDATE
                ON scholarships FOR EACH ROW EXECUTE FUNCTION scholarships_update_fts();
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scholarships');
    }
};

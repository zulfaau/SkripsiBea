<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$statements = [
    // Step 1
    "CREATE OR REPLACE FUNCTION scholarships_update_fts() RETURNS trigger AS $$
    BEGIN
      new.fts_content :=
        setweight(to_tsvector('indonesian', coalesce(new.nama_beasiswa, '')), 'A') ||
        setweight(to_tsvector('indonesian', coalesce(new.benua, '')), 'B') ||
        setweight(to_tsvector('indonesian', coalesce(new.negara, '')), 'B') ||
        setweight(to_tsvector('indonesian', coalesce(new.deskripsi, '')), 'C');
      RETURN new;
    END
    $$ LANGUAGE plpgsql;",

    // Step 2
    "UPDATE scholarships SET 
    fts_content = 
        setweight(to_tsvector('indonesian', coalesce(nama_beasiswa, '')), 'A') ||
        setweight(to_tsvector('indonesian', coalesce(benua, '')), 'B') ||
        setweight(to_tsvector('indonesian', coalesce(negara, '')), 'B') ||
        setweight(to_tsvector('indonesian', coalesce(deskripsi, '')), 'C');",

    // Step 3 (Drop function)
    "DROP FUNCTION IF EXISTS hybrid_search(text, vector, integer)",

    // Step 4 (Create function)
    "CREATE OR REPLACE FUNCTION hybrid_search(query_text TEXT, query_embedding VECTOR(1536), match_count INT)
    RETURNS TABLE (
        id BIGINT,
        nama_beasiswa VARCHAR,
        benua VARCHAR,
        negara VARCHAR,
        jenjang VARCHAR,
        deskripsi TEXT,
        benefit TEXT,
        persyaratan TEXT,
        deadline VARCHAR,
        kategori VARCHAR,
        similarity FLOAT8
    ) AS $$
    BEGIN
      RETURN QUERY
      SELECT
        s.id,
        s.nama_beasiswa,
        s.benua,
        s.negara,
        s.jenjang,
        s.deskripsi,
        s.benefit,
        s.persyaratan,
        s.deadline,
        s.kategori,
        (
          (1 - (s.embedding <=> query_embedding)) * 0.5 +
          ts_rank_cd(s.fts_content, websearch_to_tsquery('indonesian', query_text)) * 0.5
        )::FLOAT8 AS similarity
      FROM scholarships s
      ORDER BY similarity DESC
      LIMIT match_count;
    END;
    $$ LANGUAGE plpgsql;"
];

foreach ($statements as $i => $sql) {
    try {
        echo "Executing statement " . ($i + 1) . "... ";
        DB::statement($sql);
        echo "SUCCESS\n";
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

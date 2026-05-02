import pandas as pd
from sentence_transformers import SentenceTransformer
import os

# 1. Konfigurasi
EXCEL_PATH = r"C:\Users\zulfa\Documents\Materi Smt 8\FILE SKRIPSI\Jurnal\dataset_schoters_2026_2027.xlsx"
# Model gratisan yang ringan dan bagus (Ukuran vektor: 384)
MODEL_NAME = 'all-MiniLM-L6-v2'

print("--- Memuat Model AI Lokal (Gratis)... ---")
model = SentenceTransformer(MODEL_NAME)

def clean(val):
    if pd.isna(val):
        return ""
    return str(val).replace("'", "''").replace("\n", " ")

# 2. Baca Excel
print(f"--- Membaca Excel ---")
df = pd.read_excel(EXCEL_PATH)
sql_statements = []

print(f"Memproses {len(df)} data beasiswa...")

for index, row in df.iterrows():
    nama = clean(row['Nama Beasiswa'])
    print(f"[{index+1}/{len(df)}] Menghitung vektor: {nama}")
    
    # Gabungkan teks agar AI paham konteks
    text_to_embed = f"Beasiswa {nama} di {row['Negara']}. {row['Jenjang']} {row['Jurusan']}. {row['Deskripsi']}"
    
    # Hitung vektor secara lokal (tanpa API)
    embedding = model.encode(text_to_embed).tolist()
    
    # Buat perintah SQL
    sql = f"INSERT INTO scholarships (name, continent, country, level, description, deadline, category, major, benefit, requirements, source, url, original_url, embedding) VALUES ("
    sql += f"'{nama}', "
    sql += f"'{clean(row['Benua'])}', "
    sql += f"'{clean(row['Negara'])}', "
    sql += f"'{clean(row['Jenjang'])}', "
    sql += f"'{clean(row['Deskripsi'])}', "
    sql += f"'{clean(row['Deadline'])}', "
    sql += f"'{clean(row['Kategori'])}', "
    sql += f"'{clean(row['Jurusan'])}', "
    sql += f"'{clean(row['Benefit'])}', "
    sql += f"'{clean(row['Persyaratan'])}', "
    sql += f"'{clean(row['Sumber'])}', "
    sql += f"'{clean(row['URL'])}', "
    sql += f"'{clean(row['URL ASLI'])}', "
    sql += f"'{embedding}');"
    sql_statements.append(sql)

# 3. Simpan hasil
with open("data_beasiswa_gratis.sql", "w", encoding="utf-8") as f:
    f.write("\n".join(sql_statements))

print(f"\n--- SELESAI ---")
print(f"File hasil: data_beasiswa_gratis.sql")
print(f"Langkah selanjutnya: Copy isi file tersebut ke SQL Editor Supabase.")

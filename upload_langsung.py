import pandas as pd
from sentence_transformers import SentenceTransformer
from supabase import create_client, Client
import time

# 1. KONFIGURASI
SUPABASE_URL = "https://csegvqogdpjlbswpgxqz.supabase.co"
SUPABASE_KEY = "MASUKKAN_SERVICE_ROLE_KEY_DI_SINI"
EXCEL_PATH = r"C:\Users\zulfa\Documents\Materi Smt 8\FILE SKRIPSI\Jurnal\dataset_schoters_2026_2027.xlsx"
MODEL_NAME = 'all-MiniLM-L6-v2' # Ukuran vektor 384

print("--- Memuat Model AI (Gratis) & Koneksi Supabase ---")
supabase: Client = create_client(SUPABASE_URL, SUPABASE_KEY)
model = SentenceTransformer(MODEL_NAME)

def clean(val):
    if pd.isna(val):
        return ""
    return str(val).strip()

# 2. Baca Excel
print(f"--- Membaca Excel ---")
df = pd.read_excel(EXCEL_PATH)

print(f"Memproses {len(df)} data beasiswa...")

batch_data = []
for index, row in df.iterrows():
    nama = clean(row['Nama Beasiswa'])
    print(f"[{index+1}/{len(df)}] Menghitung vektor & Menyiapkan: {nama}")
    
    # Gabungkan teks untuk konteks AI
    text_to_embed = f"Beasiswa {nama} di {row['Negara']}. {row['Jenjang']} {row['Jurusan']}. {row['Deskripsi']}"
    
    # Hitung vektor
    embedding = model.encode(text_to_embed).tolist()
    
    # Siapkan data untuk Supabase
    data = {
        "name": nama,
        "continent": clean(row['Benua']),
        "country": clean(row['Negara']),
        "level": clean(row['Jenjang']),
        "description": clean(row['Deskripsi']),
        "deadline": clean(row['Deadline']),
        "category": clean(row['Kategori']),
        "major": clean(row['Jurusan']),
        "benefit": clean(row['Benefit']),
        "requirements": clean(row['Persyaratan']),
        "source": clean(row['Sumber']),
        "url": clean(row['URL']),
        "original_url": clean(row['URL ASLI']),
        "embedding": embedding
    }
    batch_data.append(data)
    
    # Upload setiap 50 data agar tidak overload
    if len(batch_data) >= 50:
        print(f"--- Mengunggah batch 50 data ke Supabase... ---")
        supabase.table("scholarships").insert(batch_data).execute()
        batch_data = []
        time.sleep(1)

# Upload sisa data
if batch_data:
    print(f"--- Mengunggah sisa data ke Supabase... ---")
    supabase.table("scholarships").insert(batch_data).execute()

print(f"\n--- SELESAI! ---")
print(f"Semua data sudah berhasil masuk ke database Supabase kamu.")
print(f"Silakan cek di 'Table Editor' pada Dashboard Supabase.")

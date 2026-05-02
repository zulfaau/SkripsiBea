import pandas as pd
import requests
import json
from supabase import create_client, Client
import time

# ================= KONFIGURASI =================
SUPABASE_URL = "https://csegvqogdpjlbswpgxqz.supabase.co"
SUPABASE_KEY = "MASUKKAN_SERVICE_ROLE_KEY_DISINI"
OPENROUTER_API_KEY = "MASUKKAN_OPENROUTER_KEY_DISINI"

# Model Google yang sangat disarankan untuk RAG (Vektor: 768)
MODEL_NAME = "google/text-embedding-004"
EXCEL_PATH = r"C:\Users\zulfa\Documents\Materi Smt 8\FILE SKRIPSI\Jurnal\dataset_schoters_2026_2027.xlsx"
# ===============================================

supabase: Client = create_client(SUPABASE_URL, SUPABASE_KEY)

def get_embedding(text):
    url = "https://openrouter.ai/api/v1/embeddings"
    headers = {
        "Authorization": f"Bearer {OPENROUTER_API_KEY}",
        "Content-Type": "application/json"
    }
    payload = {
        "model": MODEL_NAME,
        "input": text
    }
    try:
        response = requests.post(url, headers=headers, json=payload)
        res_json = response.json()
        return res_json['data'][0]['embedding']
    except Exception as e:
        print(f"Error AI: {e}")
        return None

def clean(val):
    if pd.isna(val): return ""
    return str(val).strip()

print("--- Memulai Proses OpenRouter Upload ---")
df = pd.read_excel(EXCEL_PATH)

batch_data = []
for index, row in df.iterrows():
    nama = clean(row['Nama Beasiswa'])
    print(f"[{index+1}/{len(df)}] Embedding via OpenRouter: {nama}")
    
    content = f"Beasiswa {nama} di {row['Negara']}. {row['Jenjang']} {row['Jurusan']}. {row['Deskripsi']}"
    vector = get_embedding(content)
    
    if vector:
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
            "embedding": vector
        }
        batch_data.append(data)

    # Kirim ke Supabase setiap 20 data (biar aman)
    if len(batch_data) >= 20:
        print("--- Mengirim batch ke Supabase... ---")
        supabase.table("scholarships").insert(batch_data).execute()
        batch_data = []
        time.sleep(1) # Jeda agar tidak kena rate limit

if batch_data:
    supabase.table("scholarships").insert(batch_data).execute()

print("\n--- SELESAI! Database kamu sekarang menggunakan Vektor OpenRouter (768 Dim) ---")

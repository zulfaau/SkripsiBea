import pandas as pd
import os
import json
import hashlib
import psycopg2
import re  # <-- Tambahkan library regex
from psycopg2.extras import execute_values
from openai import OpenAI
from dotenv import load_dotenv

load_dotenv()

# Konfigurasi Database
DB_HOST = os.getenv("DB_HOST")
DB_PORT = os.getenv("DB_PORT", 6543)
DB_NAME = os.getenv("DB_DATABASE")
DB_USER = os.getenv("DB_USERNAME")
DB_PASS = os.getenv("DB_PASSWORD")
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY")
EXCEL_FILE = "dataset_beasiswa.xlsx"
EMBED_CACHE_FILE = "embedding_cache.json"

# Setup AI
if not OPENAI_API_KEY:
    raise SystemExit("[ERROR] OPENAI_API_KEY tidak ditemukan di .env")

if OPENAI_API_KEY.startswith("sk-or"):
    client = OpenAI(api_key=OPENAI_API_KEY, base_url="https://openrouter.ai/api/v1")
    MODEL_NAME = "openai/text-embedding-3-small"
else:
    client = OpenAI(api_key=OPENAI_API_KEY)
    MODEL_NAME = "text-embedding-3-small"


def get_embedding(text):
    return client.embeddings.create(input=[text.replace("\n", " ")], model=MODEL_NAME).data[0].embedding


def find_column(df, possible_names):
    for name in possible_names:
        for col in df.columns:
            if col.strip().lower() == name.strip().lower():
                return col
    return None


def load_cache():
    if os.path.exists(EMBED_CACHE_FILE):
        try:
            with open(EMBED_CACHE_FILE, "r") as f:
                return json.load(f)
        except Exception:
            print("[WARN] Cache rusak, mulai dari kosong.")
    return {}


def save_cache(cache):
    with open(EMBED_CACHE_FILE, "w") as f:
        json.dump(cache, f)

# --- FUNGSI BARU: CLEAN DEADLINE ---
# --- FUNGSI BARU: CLEAN DEADLINE (UPDATE) ---
def clean_deadline(text):
    if not text or str(text).strip().lower() == 'nan':
        return ""
    
    text = str(text).strip()
    
    # Mapping untuk mengubah angka bulan menjadi nama bulan dalam Bahasa Indonesia
    bulan_map = {
        '01': 'Januari', '1': 'Januari',
        '02': 'Februari', '2': 'Februari',
        '03': 'Maret', '3': 'Maret',
        '04': 'April', '4': 'April',
        '05': 'Mei', '5': 'Mei',
        '06': 'Juni', '6': 'Juni',
        '07': 'Juli', '7': 'Juli',
        '08': 'Agustus', '8': 'Agustus',
        '09': 'September', '9': 'September',
        '10': 'Oktober',
        '11': 'November',
        '12': 'Desember'
    }

    # POLA 1: Menangkap format Datetime Pandas (Contoh: 2026-04-30 00:00:00)
    # Kita cari pola YYYY-MM-DD
    pattern_timestamp = r"\b(\d{4})-(\d{2})-(\d{2})\b"
    match_ts = re.search(pattern_timestamp, text)
    
    if match_ts:
        tahun = match_ts.group(1)
        bulan_angka = match_ts.group(2)
        tanggal = match_ts.group(3)
        
        # Hilangkan angka 0 di depan tanggal agar rapi (misal "06" jadi "6")
        tanggal_rapi = str(int(tanggal)) 
        nama_bulan = bulan_map.get(bulan_angka, "")
        
        return f"{tanggal_rapi} {nama_bulan} {tahun}"

    # POLA 2: Menangkap format teks berantakan (Contoh: Gelombang II : 25 Juni 2026)
    # Pola: 1-2 angka, spasi, alfabet (nama bulan), spasi, 4 angka (tahun)
    pattern_text = r"\b(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})\b"
    matches = re.findall(pattern_text, text)
    
    if matches:
        # Jika ketemu format tanggal, ambil hasil match TERAKHIR.
        last_match = matches[-1]
        cleaned_date = f"{last_match[0]} {last_match[1].capitalize()} {last_match[2]}"
        return cleaned_date
        
    # Jika tidak cocok Pola 1 maupun Pola 2, kembalikan teks aslinya
    return text
# -----------------------------------


def main():
    if not os.path.exists(EXCEL_FILE):
        print("[ERROR] File Excel tidak ada!")
        return

    df = pd.read_excel(EXCEL_FILE)
    col_map = {
        'nama': find_column(df, ['nama beasiswa', 'nama', 'beasiswa']),
        'benua': find_column(df, ['benua']), 'negara': find_column(df, ['negara']),
        'jenjang': find_column(df, ['jenjang']), 'deskripsi': find_column(df, ['deskripsi']),
        'deadline': find_column(df, ['deadline']), 'kategori': find_column(df, ['kategori']),
        'jurusan': find_column(df, ['jurusan']), 'benefit': find_column(df, ['benefit']),
        'persyaratan': find_column(df, ['persyaratan']), 'sumber': find_column(df, ['sumber']),
        'url': find_column(df, ['url']), 'url_asli': find_column(df, ['url asli'])
    }

    cache = load_cache()
    data_to_insert = []
    used_hashes = set()
    stat_embed = stat_cache = stat_fail = 0
    total = len(df)

    for index, row in df.iterrows():
        nama = str(row[col_map['nama']])
        if not nama or nama == 'nan' or nama.strip() == '':
            continue
            
        # Ambil dan bersihkan data deadline terlebih dahulu
        raw_deadline = str(row.get(col_map['deadline'], ''))
        cleaned_deadline = clean_deadline(raw_deadline)

        # Bangun konteks lalu hash-nya (kunci cache embedding)
        context = f"Beasiswa: {nama}\n"
        for key, col in col_map.items():
            if col and key != 'nama':
                # Gunakan cleaned_deadline khusus untuk kolom deadline
                if key == 'deadline':
                    val = cleaned_deadline
                else:
                    val = str(row[col])
                    
                if val and val != 'nan':
                    context += f"{key.capitalize()}: {val}\n"
                    
        context = context.strip()
        h = hashlib.sha256(context.encode("utf-8")).hexdigest()
        used_hashes.add(h)

        if h in cache:
            embedding = cache[h]
            stat_cache += 1
            print(f"[{index + 1}/{total}] CACHE : {nama}")
        else:
            try:
                embedding = get_embedding(context)
                cache[h] = embedding
                stat_embed += 1
                print(f"[{index + 1}/{total}] EMBED : {nama}")
                if stat_embed % 25 == 0:
                    save_cache(cache)
            except Exception as e:
                stat_fail += 1
                print(f"[ERROR] Gagal embedding baris {index + 1} ({nama}): {e}")
                continue

        data_to_insert.append([
            nama, str(row.get(col_map['benua'], '')), str(row.get(col_map['negara'], '')),
            str(row.get(col_map['jenjang'], '')), str(row.get(col_map['deskripsi'], '')),
            cleaned_deadline, # <-- Masukkan yang sudah bersih ke Database
            str(row.get(col_map['kategori'], '')),
            str(row.get(col_map['jurusan'], '')), str(row.get(col_map['benefit'], '')),
            str(row.get(col_map['persyaratan'], '')), str(row.get(col_map['sumber'], '')),
            str(row.get(col_map['url'], '')), str(row.get(col_map['url_asli'], '')),
            embedding
        ])

    cache = {k: v for k, v in cache.items() if k in used_hashes}
    save_cache(cache)
    print(f"\n[RINGKAS] Embed baru: {stat_embed} | Dari cache: {stat_cache} | Gagal: {stat_fail} | Siap upload: {len(data_to_insert)}")

    if not data_to_insert:
        print("[ERROR] Tidak ada data valid untuk diupload.")
        return

    print(f"\nMenghubungkan ke database {DB_HOST}...")
    try:
        conn = psycopg2.connect(
            host=DB_HOST, port=DB_PORT, database=DB_NAME,
            user=DB_USER, password=DB_PASS, connect_timeout=10
        )
        cur = conn.cursor()
        print("Mengganti seluruh data (refresh penuh)...")
        cur.execute("TRUNCATE TABLE scholarships RESTART IDENTITY CASCADE;")

        query = """INSERT INTO scholarships (nama_beasiswa, benua, negara, jenjang, deskripsi, deadline, kategori, jurusan, benefit, persyaratan, sumber, url, url_asli, embedding) VALUES %s"""
        execute_values(cur, query, data_to_insert)
        conn.commit()
        print(f"[SUCCESS] {len(data_to_insert)} data masuk ke Supabase. Cache embedding disimpan untuk run berikutnya.")

        cur.close()
        conn.close()
    except Exception as e:
        print(f"\n[ERROR] DATABASE ERROR: {e}")
        print("\n[INFO] SARAN:")
        print("1. Cek Dashboard Supabase -> Settings -> Database")
        print("2. Pastikan DB_HOST/DB_PORT di .env benar (Transaction Pooler = port 6543)")
        print(f"3. Tenang, embedding sudah tersimpan di '{EMBED_CACHE_FILE}'. Jalankan ulang TIDAK akan bayar API lagi untuk baris yang sama.")


if __name__ == "__main__":
    main()
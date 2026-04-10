import os
import time
import requests
from dotenv import load_dotenv

load_dotenv("/Users/macbookair/Magang-Istana/python-ai/.env")
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN")

if not GITHUB_TOKEN:
    print("❌ GITHUB_TOKEN tidak ditemukan di .env")
    exit(1)

# Endpoint khusus untuk Chat
API_URL = "https://models.inference.ai.azure.com/chat/completions"
HEADERS = {
    "Authorization": f"Bearer {GITHUB_TOKEN}",
    "Content-Type": "application/json"
}

PAYLOAD = {
    "model": "gpt-5-chat",
    "messages": [
        {"role": "user", "content": "Jawab singkat: Halo."}
    ]
}

def run_test():
    print("=== Mengetes GitHub Models API (text-embedding-3-large) ===")
    
    start_time = time.time()
    try:
        response = requests.post(API_URL, headers=HEADERS, json=PAYLOAD)
        latency = time.time() - start_time
        
        status_code = response.status_code
        print(f"Status Code: {status_code}")
        
        if status_code == 200:
            print(f"✅ Berhasil! Latency: {latency:.2f} detik")
            data = response.json()
            
            # Mendapatkan output array dimensi vector
            embeddings = data.get("data", [])
            print(f"Jumlah vector yang dihasilkan: {len(embeddings)}")
            if len(embeddings) > 0:
                dimensi = len(embeddings[0]["embedding"])
                print(f"Dimensi Vektor (Large): {dimensi} (Seharusnya ~3072 dimensi)")
                print(f"Sampel 5 Angka Vektor Pertama: {embeddings[0]['embedding'][:5]}")
            
            print("\n=== Analisis Rate Limit (RPM / RPD / Tokens) ===")
            headers = response.headers
            
            rate_limit_headers = [
                'x-ratelimit-limit-requests',
                'x-ratelimit-remaining-requests',
                'x-ratelimit-limit-tokens',
                'x-ratelimit-remaining-tokens'
            ]
            
            for k in rate_limit_headers:
                if k in headers:
                    print(f" - {k}: {headers[k]}")
                    
        else:
            print(f"❌ Gagal Request. Body: {response.text}")
            
    except Exception as e:
        print(f"❌ Error HTTP: {e}")

if __name__ == "__main__":
    run_test()

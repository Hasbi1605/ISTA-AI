import os
import requests
from dotenv import load_dotenv

load_dotenv("/Users/macbookair/Magang-Istana/python-ai/.env")
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN")

if not GITHUB_TOKEN:
    print("❌ Token tidak ditemukan!")
    exit(1)

API_URL = "https://models.inference.ai.azure.com/chat/completions"
HEADERS = {
    "Authorization": f"Bearer {GITHUB_TOKEN}",
    "Content-Type": "application/json"
}
PAYLOAD = {
    "model": "gpt-4o",
    "messages": [
        {"role": "user", "content": "Test limit"}
    ]
}

print("Mengambil data limit untuk model gpt-4o...")
response = requests.post(API_URL, headers=HEADERS, json=PAYLOAD)

if response.status_code == 200:
    print("✅ Berhasil. Berikut Rate Limit Headers:")
    headers = response.headers
    rate_limit_headers = [
        'x-ratelimit-limit-requests',
        'x-ratelimit-remaining-requests',
        'x-ratelimit-limit-tokens',
        'x-ratelimit-remaining-tokens',
        'x-ratelimit-reset-requests',
        'retry-after'
    ]
    for k in rate_limit_headers:
        if k in headers:
            print(f" - {k}: {headers[k]}")
    import json
    data = response.json()
    print("\nResponse: ", data['choices'][0]['message']['content'])
else:
    print(f"❌ Error {response.status_code}: {response.text}")


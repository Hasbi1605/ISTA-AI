import os
import requests
from dotenv import load_dotenv

load_dotenv("/Users/macbookair/Magang-Istana/python-ai/.env")
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN")

API_URL = "https://models.inference.ai.azure.com/embeddings"
HEADERS = {
    "Authorization": f"Bearer {GITHUB_TOKEN}",
    "Content-Type": "application/json"
}

def test_model(model_name):
    print(f"\nModel: {model_name}")
    payload = {"model": model_name, "input": ["Hello world"]}
    res = requests.post(API_URL, headers=HEADERS, json=payload)
    if res.status_code == 200:
        h = res.headers
        print(f"✅ Success")
        for k in ['x-ratelimit-limit-requests', 'x-ratelimit-remaining-requests', 'x-ratelimit-limit-tokens', 'x-ratelimit-remaining-tokens']:
            if k in h: print(f" - {k}: {h[k]}")
    else:
        print(f"❌ Failed: {res.status_code} - {res.text}")

test_model("text-embedding-3-large")
test_model("text-embedding-3-small")

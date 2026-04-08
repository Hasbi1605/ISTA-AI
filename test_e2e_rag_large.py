import requests
import os
import time

API_BASE = "http://localhost:8001/api"
TOKEN = "your_internal_api_secret"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}"
}

PDF_URL = "https://bitcoin.org/bitcoin.pdf"
FILENAME = "bitcoin_whitepaper.pdf"

def run_large_test():
    print("=== Memulai End-to-End Test RAG ISTA AI (Dokumen Besar) ===")
    
    # 1. Download document
    print(f"\n[1] Mendownload dokumen besar dari {PDF_URL}...")
    try:
        r = requests.get(PDF_URL)
        r.raise_for_status()
        with open(FILENAME, "wb") as f:
            f.write(r.content)
        file_size = os.path.getsize(FILENAME)
        print(f"✅ Download berhasil! Ukuran file: {file_size / 1024:.2f} KB")
    except Exception as e:
        print(f"❌ Download gagal: {e}")
        return

    # 2. Upload and process file
    print("\n[2] Upload file ke RAG ISTA AI (Proses ini mungkin memakan waktu untuk chunking & embedding)...")
    upload_url = f"{API_BASE}/documents/process"
    
    with open(FILENAME, "rb") as f:
        files = {"file": (FILENAME, f, "application/pdf")}
        data = {"user_id": "test_e2e_large_user"}
        
        start_time = time.time()
        try:
            response = requests.post(upload_url, headers=HEADERS, files=files, data=data)
            response.raise_for_status()
            res_json = response.json()
            elapsed = time.time() - start_time
            print(f"✅ Upload & Embedding berhasil dalam {elapsed:.2f} detik: {res_json}")
        except Exception as e:
            print(f"❌ Upload gagal: {e}")
            if hasattr(response, 'text'):
                print(f"Detail: {response.text}")
            return

    # Beri waktu sebentar memastikan data masuk indeks dengan aman
    time.sleep(2)

    # 3. Test Chat Query via RAG
    print("\n[3] Kirim pesan ke API Chat dengan RAG...")
    chat_url = f"{API_BASE}/chat"
    questions = [
        "What is the main problem that this paper tries to solve?",
        "How does the Proof-of-Work mechanism described here work?"
    ]

    for q in questions:
        print(f"\n* Pertanyaan: {q}")
        chat_payload = {
            "messages": [{"role": "user", "content": q}],
            "document_filenames": [FILENAME],
            "user_id": "test_e2e_large_user"
        }
        
        try:
            response = requests.post(chat_url, headers=HEADERS, json=chat_payload, stream=True)
            response.raise_for_status()
            
            print("-" * 50)
            for chunk in response.iter_content(chunk_size=1024):
                if chunk:
                    print(chunk.decode("utf-8"), end="", flush=True)
            print("\n" + "-" * 50)
        except Exception as e:
            print(f"\n❌ Test Chat gagal untuk pertanyaan ini: {e}")
            if hasattr(response, 'text'):
                print(f"Detail: {response.text}")

    # 4. Cleanup Vector Database
    print("\n[4] Membersihkan index RAG...")
    delete_url = f"{API_BASE}/documents/{FILENAME}"
    try:
        response = requests.delete(delete_url, headers=HEADERS)
        response.raise_for_status()
        print(f"✅ Cleanup berhasil: {response.json()}")
    except Exception as e:
        print(f"❌ Cleanup gagal: {e}")
        if hasattr(response, 'text'):
            print(f"Detail: {response.text}")
            
    # Hapus file PDF lokal
    if os.path.exists(FILENAME):
        os.remove(FILENAME)
        
if __name__ == "__main__":
    run_large_test()

import requests
import json
import os
import time

API_BASE = "http://localhost:8001/api"
TOKEN = "your_internal_api_secret"
HEADERS = {
    "Authorization": f"Bearer {TOKEN}"
}

def run_test():
    print("=== Memulai End-to-End Test RAG ISTA AI ===")
    
    # 1. Buat dummy file
    filename = "dummy_test_rag.txt"
    content = "RAG ISTA AI adalah sebuah sistem canggih yang dibuat pada tahun 2026. Sistem ini dapat menjawab pertanyaan berdasarkan dokumen yang diunggah. CEO dari perusahaan ini bernama Bapak Budi Santoso."
    
    with open(filename, "w") as f:
        f.write(content)
        
    print(f"\n[1] File dummy '{filename}' berhasil dibuat.")
    
    # 2. Upload and process file
    print("\n[2] Upload file ke RAG ISTA AI...")
    upload_url = f"{API_BASE}/documents/process"
    
    with open(filename, "rb") as f:
        files = {"file": (filename, f, "text/plain")}
        data = {"user_id": "test_e2e_user"}
        
        try:
            response = requests.post(upload_url, headers=HEADERS, files=files, data=data)
            response.raise_for_status()
            res_json = response.json()
            print(f"✅ Upload berhasil: {res_json}")
        except Exception as e:
            print(f"❌ Upload gagal: {e}")
            if hasattr(response, 'text'):
                print(f"Detail: {response.text}")
            return

    # Bersihkan file lokal setelah upload (optional, tapi baik untuk clean up)
    if os.path.exists(filename):
        os.remove(filename)

    # Beri sedikit waktu untuk memastikan data masuk (meski ChromaDB sinkron, sometimes indexing latency)
    time.sleep(2)

    # 3. Test Chat Query via RAG
    print("\n[3] Kirim pesan ke API Chat dengan RAG...")
    chat_url = f"{API_BASE}/chat"
    chat_payload = {
        "messages": [
            {"role": "user", "content": "Siapa nama CEO dari perusahaan pembuat RAG ISTA AI dan kapan sistem ini dibuat?"}
        ],
        "document_filenames": [filename],
        "user_id": "test_e2e_user"
    }

    print(f"Pertanyaan: {chat_payload['messages'][0]['content']}")
    
    try:
        response = requests.post(chat_url, headers=HEADERS, json=chat_payload, stream=True)
        response.raise_for_status()
        
        print("\nJawaban dari AI:")
        print("-" * 50)
        for chunk in response.iter_content(chunk_size=1024):
            if chunk:
                # Karena berupa server-sent events atau streaming text, kita print langsung
                print(chunk.decode("utf-8"), end="", flush=True)
        print("\n" + "-" * 50)
        print("\n✅ Test RAG Chat berhasil.")
    except Exception as e:
        print(f"\n❌ Test Chat gagal: {e}")
        if hasattr(response, 'text'):
            print(f"Detail: {response.text}")
            
    # 4. Cleanup Vector Database
    print("\n[4] Membersihkan index RAG...")
    delete_url = f"{API_BASE}/documents/{filename}"
    try:
        response = requests.delete(delete_url, headers=HEADERS)
        response.raise_for_status()
        print(f"✅ Cleanup berhasil: {response.json()}")
    except Exception as e:
        print(f"❌ Cleanup gagal: {e}")
        if hasattr(response, 'text'):
            print(f"Detail: {response.text}")

if __name__ == "__main__":
    run_test()

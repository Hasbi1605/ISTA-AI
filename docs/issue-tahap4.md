# Tahap 4: RAG — Chat dengan Dokumen

## Objective
User dapat bertanya tentang isi dokumen yang sudah diupload, dan AI menjawab berdasarkan isi dokumen tersebut dengan menyertakan sumber referensi.

---

## Overview Alur

```
User bertanya → Laravel kirim ke Python /api/chat dengan document_ids 
→ Python lakukan vector search di ChromaDB → ambil chunks relevan 
→ gabungkan dengan pertanyaan sebagai konteks → kirim ke LLM 
→ return jawaban + sumber referensi → tampilkan di UI
```

---

## Bagian 1: Backend Python (Kerjakan Duluan)

### 1.1 Modifikasi Endpoint `/api/chat` untuk Mode RAG

**File:** `python-ai/app/routers/chat.py`

- Tambahkan parameter opsional `document_ids: List[int]` pada request body
- Jika `document_ids` ada:
  1. Lakukan vector search di ChromaDB untuk mencari chunks relevan berdasarkan query user
  2. Ambil top-K chunks (misal K=5)
  3. Format chunks sebagai konteks tambahan
  4. Gabungkan konteks + pertanyaan user dalam prompt ke LLM
  5. Return jawaban beserta metadata sumber (nama dokumen, halaman/posisi chunk)
- Jika `document_ids` kosong, jalankan chat biasa seperti sebelumnya

### 1.2 Buat Service RAG

**File:** `python-ai/app/services/rag_service.py`

- Fungsi `search_relevant_chunks(query: str, document_ids: List[int], top_k: int)`:
  - Generate embedding dari query
  - Query ChromaDB dengan filter document_ids
  - Return list chunks dengan metadata (document_id, chunk_index, content, score)

- Fungsi `build_rag_prompt(question: str, chunks: List)`:
  - Format chunks menjadi konteks yang readable
  - Gabungkan dengan instruksi sistem untuk RAG (misal: "Jawab berdasarkan dokumen berikut...")
  - Return prompt lengkap

### 1.3 Buat Endpoint `/api/documents/summarize`

**File:** `python-ai/app/routers/documents.py`

- Endpoint `POST /api/documents/summarize`
- Input: `document_id: int`
- Proses:
  1. Ambil semua chunks dari dokumen tersebut di ChromaDB
  2. Jika dokumen pendek, kirim semua ke LLM untuk di-summarize
  3. Jika dokumen panjang, lakukan summarization bertahap (chunk by chunk lalu combine)
- Output: summary dalam bentuk teks

### 1.4 Testing

- Test via Swagger UI (`/docs`):
  - Upload dokumen via endpoint tahap 3
  - Kirim pertanyaan dengan `document_ids` → verifikasi jawaban relevan
  - Test endpoint summarize → verifikasi summary akurat

---

## Bagian 2: Frontend Laravel

### 2.1 Update Chat UI untuk Mendukung RAG

**File:** `laravel/app/Livewire/Chat.php` atau component chat yang sudah ada

- Tambahkan state untuk menyimpan dokumen yang dipilih user untuk di-chat
- Tampilkan dropdown/selector dokumen di atas area chat
- User bisa memilih:
  - "Chat tanpa dokumen" (mode biasa)
  - "Chat dengan dokumen tertentu" (pilih satu/beberapa)
  - "Chat dengan semua dokumen saya"

### 2.2 Tampilkan Sumber Referensi di Jawaban

- Setiap jawaban AI yang menggunakan RAG harus menampilkan:
  - Nama dokumen yang menjadi sumber
  - Posisi/halaman jika tersedia
- Bisa dalam bentuk collapsible section "Sumber:" di bawah jawaban

### 2.3 Update AIService untuk Kirim document_ids

**File:** `laravel/app/Services/AIService.php`

- Modifikasi method chat untuk menerima dan mengirim `document_ids` ke Python API

### 2.4 Fitur Summarize Dokumen

- Di halaman Documents (daftar dokumen), tambahkan tombol "Rangkum" per dokumen
- Klik tombol → panggil `/api/documents/summarize` → tampilkan hasil dalam modal/popup

---

## Kriteria Selesai

- [ ] User bisa memilih dokumen yang ingin di-chat
- [ ] AI menjawab pertanyaan berdasarkan isi dokumen yang dipilih
- [ ] Setiap jawaban RAG menampilkan sumber referensi
- [ ] User bisa merangkum dokumen dengan satu klik
- [ ] Mode chat biasa (tanpa dokumen) tetap berfungsi normal

---

## Catatan Teknis

- Gunakan embedding model yang sama dengan tahap 3: **bge-m3** via Lightweight Embeddings API (gratis unlimited, tanpa API key)
- API URL: `https://lamhieu-lightweight-embeddings.hf.space/v1/embeddings`
- Dimensi embedding: 1024, max tokens: 8192
- Top-K chunks yang optimal biasanya 3-5, bisa di-tune kemudian
- Untuk dokumen panjang, pertimbangkan batasan context window LLM
- Pastikan error handling jika dokumen belum selesai di-proses (status masih "processing")
- Fallback embedding tersedia: Gemini → Jina AI → Qwen (lihat `rag_service.py`)

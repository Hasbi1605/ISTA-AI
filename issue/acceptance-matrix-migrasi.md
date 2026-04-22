# Acceptance Matrix & Parity Kontrak Migrasi AI Service ke Laravel

Dokumen ini merupakan acceptance matrix yang menjadi gate utama sebelum issue implementasi migrasi mulai dikerjakan. Seluruh check di sini harus diverifikasi pada runtime Laravel baru setelah `python-ai` di-dekomisioning atau di-*bypass*.

## 1. Capability Parity Matrix

Berikut klasifikasi fitur dan tingkat parity yang dituntut:

| Capability / Fitur | Kategori Parity | Keterangan / Blocker | Test Terkait |
| --- | --- | --- | --- |
| **Chat Tanpa Dokumen** | Exact Parity | Respons sistem wajib mempertahankan persona ISTA AI, format jawaban rapi, dan instruksi sesuai config prompt. | `test_prompt_contracts.py` |
| **Chat dengan Dokumen (RAG)** | Exact Parity | Konteks dokumen harus disisipkan ("KONTEKS DOKUMEN AKTIF"), jawaban wajib ground ke dokumen, dan tidak membuat daftar sumber di akhir jawaban. | `test_prompt_contracts.py`, `laravel/tests/Feature/Chat/ChatStreamMetadataTest.php` |
| **Web Search Realtime** | Behavior Parity | Harus melakukan pencarian internet saat dibutuhkan (implicit) atau saat dipaksa (explicit). Prompt format boleh drift ke standar tool-calling (e.g. Laravel AI SDK) namun date-awareness dan validitas harus setara. | `test_prompt_contracts.py` |
| **Upload / Process / Delete Dokumen** | Exact Parity | Support format PDF, DOCX, XLSX. Dokumen harus di-chunk dan disimpan di vector store (akan bermigrasi). Deletion harus membersihkan storage, database, dan context LLM. | `laravel/tests/Feature/Chat/DocumentUploadTest.php`, `laravel/tests/Feature/Documents/DocumentDeletionTest.php`, `laravel/tests/Feature/Jobs/ProcessDocumentTest.php` |
| **Summarization** | Behavior Parity | Bagian-bagian (partial) tetap diringkas menjadi rangkuman akhir. Kualitas ringkasan harus serupa meskipun tidak exact 1:1 karena provider/library yang berbeda. | `test_prompt_contracts.py` |
| **Source Rendering** | Exact Parity | Sumber rujukan yang di-*stream* ke frontend harus bisa di-render UI secara sama persis (format array sources yang sama atau diadaptasi secara transparan). | `laravel/tests/Feature/Chat/ChatStreamMetadataTest.php` |
| **Policy Dokumen vs Web** | Behavior Parity | Aturan "dokumen aktif men-disable auto-web search kecuali force_web_search = true" harus dipertahankan secara logic. | Implisit pada `python-ai/app/main.py` dan `rag_policy.py` |
| **Fallback & Error Handling** | Exact Parity | Copywriting untuk fallback error (misal "belum menemukan jawaban", "dokumen belum bisa dibaca") harus dipertahankan sesuai konfigurasi agar user tidak bingung. | `test_prompt_contracts.py` |

---

## 2. Rubric Evaluasi (Dual-Run / Shadow Mode)

Ketika migration branch dijalankan:

- **Kualitas Jawaban:** Jawaban harus faktual, tidak berhalusinasi di luar konteks dokumen (RAG mode), dan menggunakan gaya bahasa ISTA AI.
- **Kelengkapan Source:** Jika menjawab berdasarkan dokumen, maka `sources` JSON array harus berisi chunk yang relevan. Jika menjawab dari web, source harus mengembalikan title, url, dan snippet.
- **Policy Dokumen-vs-Web:** Chat yang melibatkan dokumen tidak boleh bocor mencari di web kecuali diminta eksplisit.
- **Kestabilan Ingest/Summarization:** Proses background `ProcessDocument` harus sukses meng-ingest file 10MB+ (atau PDF scan) dan chunk size / overlap harus seimbang sehingga summarization sukses.

---

## 3. Daftar Blocker Cutover (Go-Live)

Migrasi **TIDAK BOLEH** dimerge ke branch production sebelum:
1. Skrip/job migrasi data vector store (jika ada) terbukti aman di staging atau local.
2. Endpoint streaming Laravel menggantikan `/api/chat` sepenuhnya tanpa *breaking change* di frontend Livewire/Alpine.
3. Seluruh unit/feature test di atas "PASS" pada branch Laravel.
4. Uji *shadow mode* pada dataset manual (fixture) mengembalikan jawaban dengan kualitas (skor) >= 90% dari baseline `python-ai`.

---

## 4. Dataset Fixture

Telah disiapkan kumpulan fixture/dataset untuk shadow mode:
- Lokasi Fixture: `laravel/tests/Fixtures/migration_dataset.json`
- Skenario yang diliputi:
  - Salam/chat umum
  - Pertanyaan realtime (butuh web search)
  - Pertanyaan dengan dokumen aktif
  - Pertanyaan yang tidak ada di dokumen (out-of-context RAG)
  - Cek prioritas Dokumen vs Web (Policy Overlap)
  - Lifecycle: Upload dan Ingest Dokumen (PDF & XLSX)
  - Lifecycle: Penghapusan Dokumen (Cleanup artefak)
  - Summarization trigger (Dokumen besar)

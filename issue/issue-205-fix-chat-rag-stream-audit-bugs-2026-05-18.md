# Fix Chat Error, RAG Cleanup, and Stream Metadata Edge Cases

## Latar Belakang
Audit bug menemukan tiga area yang masih perlu diperketat:
- Python chat cascade masih dapat mengirim pesan outage sebagai jawaban assistant normal ketika tidak ada model atau semua model gagal.
- Cleanup legacy vector dokumen masih memakai filter filename yang terlalu luas pada jalur user delete.
- Parser metadata stream Laravel masih dapat menghapus teks literal yang kebetulan memuat marker internal seperti `[MODEL:...]`.

GitHub Issue: https://github.com/Hasbi1605/ISTA-AI/issues/205

## Tujuan
- Error infrastruktur LLM tersimpan dan tampil sebagai error, bukan jawaban normal.
- Cleanup legacy Chroma hanya menghapus chunk legacy tanpa `document_id`.
- Marker stream internal tidak memakan konten jawaban yang sah.
- Regression test menutup ketiga perilaku.

## Ruang Lingkup
- Update `python-ai/app/services/llm_streaming.py` untuk sentinel error cascade.
- Update `python-ai/app/services/rag_ingest.py` untuk cleanup legacy berbasis ID hasil metadata scan.
- Update `laravel/app/Services/ChatOrchestrationService.php` untuk parsing marker stream yang lebih ketat.
- Tambah/update test Python dan Laravel yang relevan.

## Di Luar Scope
- Mengubah provider/model fallback.
- Refactor besar arsitektur streaming.
- Mengubah UI chat selain efek dari event/error yang sudah ada.
- Migrasi data Chroma existing.

## Area / File Terkait
- `python-ai/app/services/llm_streaming.py`
- `python-ai/tests/test_llm_streaming.py`
- `python-ai/app/services/rag_ingest.py`
- `python-ai/tests/test_rag_ingest_delete.py`
- `laravel/app/Services/ChatOrchestrationService.php`
- Test Laravel chat orchestration/stream metadata.

## Risiko
- Sentinel error harus tetap kompatibel dengan Laravel `AIService::ERROR_SENTINEL`.
- Cleanup legacy harus tetap membersihkan chunk lama tanpa `document_id`, tetapi tidak boleh menghapus chunk dokumen baru yang punya `document_id`.
- Parser marker tidak boleh memecah metadata yang memang dikirim Python di awal chunk.

## Langkah Implementasi
1. Tambahkan kontrak sentinel di Python cascade ketika model list kosong atau semua model gagal.
2. Tambahkan test Python untuk output sentinel pada dua skenario tersebut.
3. Ganti cleanup legacy filename broad delete menjadi metadata scan dan delete by IDs untuk item tanpa `document_id`.
4. Update test delete RAG agar membuktikan chunk dengan `document_id` berbeda tidak ikut terhapus.
5. Perketat parsing `[MODEL:]` agar hanya diproses saat marker berada di awal buffer/chunk metadata.
6. Tambahkan test Laravel untuk literal `[MODEL:...]` di tengah jawaban.
7. Jalankan targeted test Python dan Laravel.

## Rencana Test
- `cd python-ai && source venv/bin/activate && pytest tests/test_llm_streaming.py tests/test_rag_ingest_delete.py`
- `cd laravel && php artisan test --filter=ChatStreamTest`
- Jika diperlukan, jalankan subset Laravel tambahan untuk `ChatOrchestrationService`.

## Kriteria Selesai
- Ketiga bug/regression tertutup oleh test.
- Targeted tests lulus.
- Branch dipush ke GitHub.
- Draft PR dibuat dan mengacu ke issue #205.

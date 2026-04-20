# Issue Plan: Refactor Bertahap Prioritas 1 - Modularisasi RAG Service Python

## Latar Belakang
`python-ai/app/services/rag_service.py` saat ini menjadi pusat terlalu banyak tanggung jawab sekaligus: ingest dokumen, chunking, fallback embedding, retrieval, hybrid search, HyDE, PDR, web-search policy, prompt assembly, dan summarization. Ukurannya sudah besar dan menjadi titik coupling untuk `main.py`, `llm_manager.py`, dan `documents.py`.

Kondisi ini membuat perubahan kecil berisiko memicu regresi lintas alur. Refactor perlu dilakukan, tetapi harus dibatasi agar tidak sekaligus mengubah kontrak ke Laravel, format stream, atau perilaku user-facing.

## Tujuan
- Memecah concern utama di `rag_service.py` ke modul yang lebih kecil dan jelas.
- Menjaga kontrak publik yang sekarang dipakai oleh `main.py`, `llm_manager.py`, dan `documents.py`.
- Menurunkan risiko import cycle, drift perilaku, dan kompleksitas review.
- Menyiapkan landasan agar perubahan berikutnya bisa dilakukan per area, bukan di satu file besar.

## Ruang Lingkup
- Refactor bertahap di sisi Python AI service.
- Mempertahankan `rag_service.py` sebagai facade sementara agar import lama tidak langsung pecah.
- Pemisahan concern minimum ke modul yang lebih fokus, misalnya:
  - ingest / vector storage
  - retrieval pipeline
  - web search policy / intent detection
  - search context / prompt context
  - summarization helpers
- Memindahkan helper internal yang sudah jelas boundary-nya tanpa mengubah hasil akhir yang diharapkan.
- Menambahkan atau memperluas test untuk area yang disentuh refactor.

## Di Luar Scope
- Mengubah UX chat atau tampilan Laravel.
- Mengubah kontrak HTTP antara Laravel dan FastAPI.
- Mengganti provider model, provider search, atau schema `ai_config.yaml`.
- Mengubah strategi produk seperti urutan fallback model, aturan dokumen-vs-web, atau format stream `[MODEL:...]` / `[SOURCES:...]`.
- Merombak penuh PDR/HyDE/hybrid search dari sisi algoritma.
- Menyelesaikan isu desain lain yang terpisah, seperti lifecycle delete vector lintas UI Laravel.

## Area / File Terkait
- [`/Users/macbookair/Magang-Istana/python-ai/app/services/rag_service.py`](/Users/macbookair/Magang-Istana/python-ai/app/services/rag_service.py)
- [`/Users/macbookair/Magang-Istana/python-ai/app/main.py`](/Users/macbookair/Magang-Istana/python-ai/app/main.py)
- [`/Users/macbookair/Magang-Istana/python-ai/app/llm_manager.py`](/Users/macbookair/Magang-Istana/python-ai/app/llm_manager.py)
- [`/Users/macbookair/Magang-Istana/python-ai/app/routers/documents.py`](/Users/macbookair/Magang-Istana/python-ai/app/routers/documents.py)
- [`/Users/macbookair/Magang-Istana/python-ai/app/services/langsearch_service.py`](/Users/macbookair/Magang-Istana/python-ai/app/services/langsearch_service.py)
- [`/Users/macbookair/Magang-Istana/python-ai/app/config_loader.py`](/Users/macbookair/Magang-Istana/python-ai/app/config_loader.py)
- [`/Users/macbookair/Magang-Istana/python-ai/config/ai_config.yaml`](/Users/macbookair/Magang-Istana/python-ai/config/ai_config.yaml)
- [`/Users/macbookair/Magang-Istana/python-ai/tests/test_ista_ai.py`](/Users/macbookair/Magang-Istana/python-ai/tests/test_ista_ai.py)
- Caller Laravel yang perlu dijaga kompatibilitasnya:
  - [`/Users/macbookair/Magang-Istana/laravel/app/Services/AIService.php`](/Users/macbookair/Magang-Istana/laravel/app/Services/AIService.php)
  - [`/Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php`](/Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php)
  - [`/Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php`](/Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php)

## Risiko
- Import cycle baru antara `rag_service.py`, `llm_manager.py`, dan router dokumen jika modul dipecah tanpa boundary yang jelas.
- Regresi pada shape hasil retrieval atau sources yang dipakai downstream oleh `main.py`, `llm_manager.py`, dan Laravel chat.
- Perubahan tidak sengaja pada perilaku dokumen-vs-web karena helper policy ikut berpindah.
- Regresi pada ingest embeddings, terutama area fixed-dimension Chroma, fallback tier, dan metadata PDR.
- Sebagian config dibaca saat import, bukan per request; refactor bisa membuat perilaku runtime-config menjadi tidak konsisten jika tidak disengaja.
- Test coverage saat ini lebih kuat di helper/config daripada di pipeline ingest dan retrieval penuh, jadi refactor tanpa test tambahan akan rapuh.

## Langkah Implementasi
1. Tetapkan boundary modul dan facade.
   - Tentukan fungsi publik yang tetap diekspos lewat `rag_service.py`.
   - Gunakan `rag_service.py` sebagai facade agar caller existing tidak berubah di tahap awal.
2. Ekstrak helper policy dan context yang paling rendah risiko.
   - Pindahkan intent detection, explicit web detection, score helper, dan `get_context_for_query()` ke modul yang fokus pada web/search policy.
   - Pastikan `main.py` dan `llm_manager.py` tetap bisa memakai API lama melalui facade.
3. Ekstrak retrieval pipeline.
   - Pindahkan helper hybrid search, BM25, HyDE, PDR resolve/filter, dan `search_relevant_chunks()` ke modul retrieval.
   - Jaga shape output chunk tetap sama.
4. Ekstrak ingest dan summarization.
   - Pindahkan `process_document()`, `delete_document_vectors()`, `get_document_chunks_for_summarization()`, dan helper embedding/token counting ke modul ingest/storage dan summarization.
   - Pastikan metadata dokumen, dimensi embedding, dan filter user tetap sama.
5. Rapikan import dan dependency direction.
   - Hindari modul baru saling mengimpor dua arah.
   - `llm_manager.py` dan router dokumen sebaiknya hanya bergantung pada facade atau modul boundary yang stabil.
6. Rapikan naming dan dokumentasi singkat internal.
   - Tambahkan komentar singkat hanya pada titik yang memang tidak langsung jelas.
   - Catat boundary modul baru agar implementasi lanjutan tidak balik menumpuk ke facade.

## Rencana Test
- Jalankan test yang sudah ada di Python, minimal:
  - `python -m pytest python-ai/tests/test_ista_ai.py`
- Tambahkan test baru untuk area yang disentuh refactor:
  - compatibility test untuk facade `rag_service.py` agar fungsi publik utama tetap bisa dipanggil
  - unit test `search_relevant_chunks()` saat rerank aktif dan saat fallback path
  - unit test `get_context_for_query()` dan `should_use_web_search()` agar reason code tidak berubah
  - unit test helper ingest/storage yang sensitif, terutama fixed embedding dimension dan metadata minimum
  - jika perlu, smoke test endpoint `main.py` / `documents.py` dengan mocking dependency inti
- Verifikasi manual ringan:
  - chat tanpa dokumen
  - chat dengan dokumen aktif
  - upload dokumen
  - summarization dokumen

## Kriteria Selesai
- `rag_service.py` tidak lagi memuat seluruh implementasi detail Prioritas 1 dalam satu file besar; sebagian besar concern inti sudah dipindahkan ke modul yang lebih fokus.
- Caller existing (`main.py`, `llm_manager.py`, `documents.py`) tetap bekerja tanpa perubahan kontrak besar.
- Kontrak output penting tetap sama:
  - hasil retrieval untuk downstream
  - stream marker untuk Laravel chat
  - filter user / filename untuk retrieval dan summarization
- Test Python yang relevan lulus, dan ada coverage baru untuk area refactor yang sebelumnya tidak cukup terlindungi.
- Tidak ada perubahan sengaja pada perilaku produk di luar scope issue ini.
- Ada catatan boundary modul baru yang cukup jelas untuk menjadi dasar refactor tahap berikutnya.

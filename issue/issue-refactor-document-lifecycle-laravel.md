# Issue Plan: Refactor Bertahap Prioritas 2 - Pusatkan Lifecycle Dokumen Laravel

## Latar Belakang
Lifecycle dokumen di Laravel saat ini tersebar di beberapa caller dengan aturan yang mirip tetapi tidak identik. Upload dokumen diduplikasi di [`ChatIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:286>) dan [`DocumentIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php:65>). Proses ingest async dijalankan oleh [`ProcessDocument`](</Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php:40>). Delete dokumen tidak konsisten: [`DocumentIndex::delete()`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php:126>) membersihkan vector di Python, sementara delete dari chat di [`ChatIndex::deleteDocument()`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:200>) dan [`deleteSelectedDocuments()`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:227>) hanya menghapus file dan row database.

Kondisi ini membuat perilaku dokumen bergantung pada entry point UI, memperbesar risiko drift, dan menyulitkan penambahan test atau perubahan API internal. Prioritas 2 perlu membatasi scope ke pemusatan orchestration dokumen di Laravel tanpa mengubah kontrak HTTP ke Python atau UX Livewire yang ada.

## Tujuan
- Memusatkan lifecycle dokumen Laravel ke satu boundary service yang jelas.
- Menghapus duplikasi logic upload, dispatch job, dan delete cleanup.
- Menyamakan perilaku delete dokumen dari chat dan halaman dokumen.
- Menurunkan coupling komponen Livewire ke storage, queue, dan HTTP call Python.
- Menambah test pada alur dokumen yang sekarang paling rawan drift.

## Ruang Lingkup
- Menambahkan service Laravel khusus lifecycle dokumen, misalnya `DocumentLifecycleService`, sebagai pusat orchestration upload, dispatch process, delete cleanup, dan guard aksi dokumen.
- Memindahkan logic bersama dari [`ChatIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:19>) dan [`DocumentIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php:17>) ke service baru.
- Menyatukan delete flow agar selalu mencakup:
  - cleanup vector di Python
  - hapus file storage
  - soft delete row `documents`
- Memusatkan dispatch [`ProcessDocument`](</Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php:15>) dari satu jalur service.
- Menjaga summarize tetap lewat [`AIService`](</Users/macbookair/Magang-Istana/laravel/app/Services/AIService.php:9>) atau boundary setara, tetapi menambahkan guard yang konsisten untuk dokumen `ready`.
- Menambah atau memperluas test Laravel untuk perilaku yang disentuh refactor.

## Di Luar Scope
- Mengubah layout atau UX halaman chat dan halaman dokumen.
- Mengubah kontrak HTTP Laravel ↔ Python (`/api/documents/process`, `/api/documents/{filename}`, `/api/documents/summarize`, `/api/chat`).
- Mengubah schema tabel `documents`.
- Mengganti strategi identifier dokumen (`filename` internal vs `original_name`) di tahap ini.
- Mengubah alur streaming chat, sources, atau policy dokumen-vs-web.
- Merombak penuh job queue, retry policy, atau infrastruktur background worker.

## Area / File Terkait
- Caller utama:
  - [`/Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php`](/Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php)
  - [`/Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php`](/Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php)
- Boundary orchestration:
  - [`/Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php`](/Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php)
  - [`/Users/macbookair/Magang-Istana/laravel/app/Services/AIService.php`](/Users/macbookair/Magang-Istana/laravel/app/Services/AIService.php)
  - File baru yang kemungkinan ditambah: `laravel/app/Services/DocumentLifecycleService.php`
- Model dan data:
  - [`/Users/macbookair/Magang-Istana/laravel/app/Models/Document.php`](/Users/macbookair/Magang-Istana/laravel/app/Models/Document.php)
  - [`/Users/macbookair/Magang-Istana/laravel/database/migrations/2026_04_06_024943_create_documents_table.php`](/Users/macbookair/Magang-Istana/laravel/database/migrations/2026_04_06_024943_create_documents_table.php)
  - [`/Users/macbookair/Magang-Istana/laravel/database/migrations/2026_04_06_080222_add_pending_status_and_soft_deletes_to_documents_table.php`](/Users/macbookair/Magang-Istana/laravel/database/migrations/2026_04_06_080222_add_pending_status_and_soft_deletes_to_documents_table.php)
- Kontrak Python yang harus tetap kompatibel:
  - [`/Users/macbookair/Magang-Istana/python-ai/app/routers/documents.py`](/Users/macbookair/Magang-Istana/python-ai/app/routers/documents.py)
- Test yang perlu diperluas:
  - [`/Users/macbookair/Magang-Istana/laravel/tests/Feature/Chat/DocumentUploadTest.php`](/Users/macbookair/Magang-Istana/laravel/tests/Feature/Chat/DocumentUploadTest.php)

## Risiko
- Refactor terlalu lebar jika service baru ikut mengambil tanggung jawab chat yang bukan lifecycle dokumen.
- Regresi delete flow jika urutan cleanup berubah dan salah satu langkah gagal.
- Drift identifier dokumen bisa tetap tersisa jika refactor tanpa menjaga penggunaan `original_name` vs `filename` secara konsisten.
- Job [`ProcessDocument`](</Users/macbookair/Magang-Istana/laravel/app/Jobs/ProcessDocument.php:40>) bisa tetap rapuh jika service baru tidak memperjelas boundary status `pending` → `processing` → `ready/error`.
- Test coverage saat ini minim untuk `DocumentIndex`, delete, summarize, dan job processing, jadi perubahan tanpa test tambahan akan sulit dipercaya.

## Langkah Implementasi
1. Tetapkan boundary service dokumen.
   - Buat service Laravel yang menjadi pintu masuk untuk operasi lifecycle dokumen.
   - Putuskan method minimum yang dibutuhkan, misalnya `upload`, `dispatchProcessing`, `delete`, `summarizeGuard`, dan helper selection/readiness jika memang perlu.
2. Pusatkan upload/create record.
   - Ambil logic validasi duplicate, quota, metadata file, storage path, create row `documents`, dan dispatch job dari [`ChatIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:286>) dan [`DocumentIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php:65>).
   - Komponen Livewire cukup memanggil service dan menangani state UI/flash message.
3. Pusatkan delete cleanup.
   - Buat satu alur delete yang selalu dipakai oleh chat dan halaman dokumen.
   - Alur ini harus konsisten memanggil delete vector Python, hapus file storage, lalu soft delete dokumen.
   - Tentukan perilaku error yang realistis, misalnya tetap lanjut soft delete dengan warning log jika cleanup vector gagal, selama itu memang keputusan yang ingin dipertahankan.
4. Rapikan boundary process dan summarize.
   - Kurangi pengetahuan `ProcessDocument` yang tersebar di caller.
   - Pastikan summarize hanya dilakukan untuk dokumen `ready`, dengan guard yang tidak tersebar acak.
5. Kecilkan tanggung jawab komponen Livewire.
   - [`ChatIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Chat/ChatIndex.php:19>) tetap mengelola state chat/UI.
   - [`DocumentIndex`](</Users/macbookair/Magang-Istana/laravel/app/Livewire/Documents/DocumentIndex.php:17>) tetap mengelola presentasi halaman dokumen.
   - Keduanya tidak lagi menjadi tempat logic storage + HTTP + queue yang berulang.
6. Tambahkan test untuk lifecycle dokumen yang paling penting.
   - Prioritaskan test yang membuktikan entry point berbeda sekarang memakai perilaku yang sama.

## Rencana Test
- Jalankan full test Laravel:
  - `cd laravel && php artisan test`
- Tambahkan test baru untuk area yang disentuh:
  - upload dokumen via `DocumentIndex`
  - delete dokumen dari chat membersihkan storage dan memanggil cleanup Python dengan perilaku yang sama seperti halaman dokumen
  - delete selected documents memakai jalur service yang sama
  - summarize menolak dokumen non-`ready`
  - job `ProcessDocument` mengubah status dengan benar pada skenario sukses dan gagal
- Jika service baru memanggil HTTP ke Python, gunakan fake/mock agar test Laravel tetap deterministik.

## Kriteria Selesai
- Upload dokumen dari chat dan halaman dokumen tidak lagi menduplikasi logic inti lifecycle.
- Delete dokumen dari seluruh entry point memakai alur cleanup yang sama.
- Boundary antara Livewire, service, job, storage, dan HTTP call ke Python lebih jelas daripada kondisi awal.
- Kontrak HTTP ke Python dan UX user-facing tetap tidak berubah.
- Test Laravel yang relevan sudah ditambahkan atau diperluas untuk lifecycle dokumen.
- Full test Laravel tetap hijau setelah refactor.

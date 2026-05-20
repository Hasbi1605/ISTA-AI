# Admin Usage model metadata untuk upload dokumen dan memo

## Latar Belakang
Halaman Admin Usage menampilkan `-` pada kolom Model untuk event `Upload Dokumen` dan `Memo: Generate`. Dari kode, kolom tersebut hanya membaca `metadata.model_label` atau `metadata.model_name`. Upload dokumen mencatat event sebelum job embedding selesai, sedangkan memo membersihkan marker `[MODEL:...]` dari output Python dan tidak mengirimkannya kembali ke Laravel.

## Tujuan
- Menjelaskan dan memperbaiki pencatatan model agar Admin Usage bisa menampilkan model embedding dokumen dan model memo.
- Menjaga metadata tetap aman: tidak menyimpan prompt, isi memo, isi dokumen, atau raw context.

## Ruang Lingkup
- Tambahkan metadata model/embedding pada event usage yang relevan.
- Buat fallback tampilan untuk event upload dokumen yang subject dokumennya sudah memiliki `embedding_provider`.
- Kirim model label memo dari Python ke Laravel melalui response header aman.
- Tambahkan test Laravel dan Python yang dekat dengan perubahan.

## Di Luar Scope
- Mengubah konfigurasi model produksi.
- Mengubah algoritma embedding, RAG, atau kualitas generate memo.
- Migrasi/backfill data event lama.

## Area / File Terkait
- `laravel/app/Jobs/ProcessDocument.php`
- `laravel/app/Services/Admin/AIUsageEventService.php`
- `laravel/app/Services/Admin/AdminMetricsService.php`
- `laravel/resources/views/livewire/admin/admin-usage.blade.php`
- `laravel/app/Services/Memo/MemoGenerationService.php`
- `python-ai/app/services/memo_generation.py`
- `python-ai/app/routers/memos.py`
- Test admin usage, process document, memo generation.

## Risiko
- Event upload lama hanya bisa menampilkan embedding provider jika subject dokumen masih ada dan sudah diproses.
- Model memo yang dipakai fallback hanya akurat bila Python mengirim marker `[MODEL:...]`; bila request gagal sebelum marker keluar, metadata tetap terbatas ke config id.
- Menambah event `document_processing` bisa mengubah jumlah event usage baru, tetapi lifecycle `started` tetap tersembunyi default.

## Langkah Implementasi
1. Tambah helper metadata model embedding pada service usage.
2. Catat event `document_processing` saat job `ProcessDocument` berhasil/gagal, termasuk embedding provider saat tersedia.
3. Eager-load subject pada listing usage dan tampilkan fallback `Document.embedding_provider` untuk event upload dokumen.
4. Simpan `model_label` pada `MemoDraft`, expose lewat header `X-AI-Model-Label`, lalu masukkan ke metadata event memo di Laravel.
5. Tambahkan test regresi untuk model metadata dokumen dan memo.

## Rencana Test
- Laravel: test `ProcessDocument` mencatat event processing dengan model embedding.
- Laravel: test Admin Usage menampilkan fallback embedding provider dari subject dokumen.
- Laravel: test memo generation event menyimpan model dari header Python.
- Python: test `generate_memo_docx` menangkap marker model dan route mengirim header.

## Kriteria Selesai
- Kolom Model tidak lagi `-` untuk event yang sudah memiliki model/embedding provider.
- Test Laravel dan Python relevan lulus.
- Risiko dan alasan akar masalah bisa dijelaskan singkat kepada user.

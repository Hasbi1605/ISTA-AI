# Issue Follow-up PR112/PR113: Summarization Runtime, OCR Config, dan Observability Laravel

## Latar Belakang

Setelah merge sampai PR #112, masih ada beberapa gap penting:

1. Fix summarization SDK bypass dari PR #113 belum masuk `main`.
2. Multi-batch summarization masih memakai prompt parsial secara hardcoded.
3. `PdfToImageRenderer` masih membaca `image_dpi` / `image_format` dari key config yang salah.
4. Runtime lokal Laravel pada workspace ini belum membawa kredensial provider yang sekarang dipakai cascade Laravel, sehingga chat jatuh ke 401.

## Tujuan

- Memastikan summarization di `main` tidak lagi bergantung pada endpoint `/responses`.
- Memperbaiki prompt multi-batch agar partial summary dan final combine memakai template yang benar.
- Menyamakan pembacaan config OCR image rendering dengan struktur `config/ai.php`.
- Memulihkan runtime chat lokal Laravel di workspace ini.
- Mendokumentasikan lokasi log Laravel yang relevan untuk observability runtime.

## Scope Implementasi

### 1. Re-apply fix summarization bypass
- Cherry-pick / re-apply perubahan setara commit `b744ef5`.
- Pastikan path summarization memanggil `/chat/completions` langsung, bukan SDK path yang memicu 404 di GitHub Models.

### 2. Fix prompt multi-batch summarization
- Ubah `buildSummarizationPrompt(...)` agar menerima mode prompt dan nomor bagian.
- Gunakan template `partial` untuk batch summary.
- Gunakan template `final` untuk langkah final combine.
- Pastikan metadata model akhir tetap berasal dari langkah combine terakhir.

### 3. Fix OCR renderer config alignment
- Ubah `PdfToImageRenderer` agar membaca `image_dpi` dan `image_format` dari `ai.ocr.*`.
- Pertahankan `max_pages` sesuai jalur OCR yang dipakai renderer.

### 4. Pulihkan runtime lokal
- Sinkronkan secret provider yang sudah ada di `python-ai/.env` ke `laravel/.env` secara lokal.
- Langkah ini hanya untuk runtime lokal dan tidak akan di-commit.

### 5. Verifikasi
- Tambah / perbarui test unit Laravel untuk prompt partial/final dan OCR config.
- Jalankan test Laravel yang relevan, lalu bila memadai lanjut `php artisan test`.
- Lakukan smoke check chat lokal singkat setelah secret Laravel tersedia.

## Risiko

- Re-apply fix summarization bisa berbenturan dengan perubahan prompt wiring di PR #112.
- Verifikasi runtime live chat bergantung pada secret lokal yang valid.
- Perubahan log/observability harus tetap minimal agar tidak menambah noise berlebihan.

## Catatan

- Temuan embedding 401 belum menjadi fokus fix ini, kecuali menghambat verifikasi chat dasar.
- Jika ada additional findings lain dari review Devin, catat sebagai tindak lanjut terpisah bila belum bisa direproduksi dari repo lokal.

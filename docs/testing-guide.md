# Testing Guide

Dokumen ini menjelaskan cara menjalankan test di repo ini, cara memilih test yang relevan, dan kapan test baru wajib ditambahkan.

## Tujuan

Panduan ini bertujuan untuk:
- memastikan setiap perubahan diverifikasi dengan benar
- membantu memilih test yang relevan berdasarkan area perubahan
- menetapkan standar minimal kapan test baru harus ditambahkan
- mengurangi risiko regresi pada Laravel dan Python

## Prinsip Umum

- Setiap perubahan kode harus diverifikasi.
- Jalankan test yang paling relevan terhadap area yang diubah.
- Jika perubahan menyentuh perilaku penting tetapi test belum ada, tambahkan test terlebih dahulu.
- Jangan menganggap tugas selesai bila verifikasi belum jelas.
- Prioritaskan test yang memverifikasi perilaku, bukan hanya implementasi internal.

## Hubungan dengan Planning

Untuk tugas yang kompleks, file issue markdown di folder `issue/` sebaiknya sudah mencantumkan:
- area Laravel yang kemungkinan perlu diuji
- area Python yang kemungkinan perlu diuji
- test yang sudah ada dan bisa dipakai
- gap test yang mungkin perlu ditambahkan

Dengan begitu, implementasi dan verifikasi tidak dimulai dari nol.

---

## Laravel

### Lokasi
Area Laravel berada di folder `laravel`.

### Perintah Test Laravel

Gunakan dari dalam folder `laravel`.

#### Menjalankan seluruh test
```bash
php artisan test
```

#### Menjalankan file test tertentu
```bash
php artisan test tests/Feature/NamaFileTest.php
```

#### Menjalankan test dengan filter nama
```bash
php artisan test --filter NamaTest
```

#### Alternatif dengan PHPUnit
```bash
vendor/bin/phpunit
```

#### Menjalankan formatter atau pengecekan tambahan jika digunakan

Sesuaikan dengan tool yang tersedia di repo, misalnya:

```bash
./vendor/bin/pint --test
```

Catatan:

- Gunakan `php artisan test` sebagai default jika tersedia.
- Jika hanya sebagian area yang berubah, jalankan test yang paling relevan lebih dulu.
- Jika perubahan cukup besar atau menyentuh perilaku inti, pertimbangkan menjalankan seluruh test Laravel.

## Python

### Lokasi
Area Python berada di folder `python-ai`.

### Environment
Sebelum menjalankan test Python, aktifkan virtual environment yang ada di folder `python-ai`.

Gunakan:

```bash
cd python-ai && source venv/bin/activate
```

Setelah environment aktif, jalankan test dari dalam folder `python-ai`.

### Perintah Test Python

#### Menjalankan seluruh test dengan pytest
```bash
cd python-ai && source venv/bin/activate && pytest
```

#### Menjalankan file test tertentu
```bash
cd python-ai && source venv/bin/activate && pytest tests/test_nama_file.py
```

#### Menjalankan test berdasarkan keyword
```bash
cd python-ai && source venv/bin/activate && pytest -k "kata_kunci"
```

#### Menjalankan dengan output lebih detail
```bash
cd python-ai && source venv/bin/activate && pytest -v
```

Catatan:

- Jangan jalankan `pytest` tanpa masuk ke `python-ai` dan mengaktifkan `venv`.
- Gunakan environment lokal yang sudah ada di `python-ai/venv`.
- Jika environment belum aktif, aktifkan dulu sebelum verifikasi Python.

## Cara Memilih Test yang Relevan

### 1. Lihat area yang berubah

Mulai dari file yang diubah:

- controller
- service
- model
- repository
- helper
- endpoint
- command
- pipeline
- utilitas bersama

### 2. Cari test yang langsung memverifikasi area itu

Pilih test yang:

- menyebut nama modul atau fitur yang sama
- berada pada folder test yang berhubungan
- menguji endpoint, service, atau perilaku yang disentuh
- sebelumnya sudah menjadi pelindung untuk area tersebut

### 3. Prioritaskan perilaku yang benar-benar berubah

Jika perubahan memengaruhi:

- hasil endpoint
- validasi
- aturan bisnis
- mapping data
- format output
- error handling
- auth atau permission
- query atau filtering
- proses background
- integrasi antarmodul

maka test harus memverifikasi perilaku tersebut secara langsung.

### 4. Pertimbangkan efek samping

Selain test untuk file yang berubah, cek juga test untuk:

- modul pemanggil
- modul yang dipanggil
- kontrak data atau response
- skenario error
- edge case yang masuk akal

### 5. Gunakan level test yang paling tepat

Secara umum:

- gunakan unit test untuk logika kecil dan terisolasi
- gunakan feature/integration test untuk endpoint, alur bisnis, atau integrasi antarbagian
- jangan menambah test yang terlalu jauh dari perilaku yang ingin diamankan

## Standar Minimal Kapan Harus Menambah Test

Tambahkan test baru jika salah satu kondisi berikut terpenuhi.

### A. Ada perilaku baru

Tambahkan test jika:

- ada fitur baru
- ada endpoint baru
- ada branch logika baru
- ada validasi baru
- ada format response baru
- ada aturan bisnis baru

### B. Ada bug fix

Tambahkan test jika:

- bug yang diperbaiki bisa direproduksi
- bug tersebut seharusnya bisa dicegah oleh test
- belum ada test yang melindungi kasus itu

Untuk bug fix, sebisa mungkin tambahkan test yang gagal sebelum perbaikan dan lolos setelah perbaikan.

### C. Ada gap test yang jelas

Tambahkan test jika:

- area penting berubah tetapi tidak ada test yang memverifikasi perilaku utamanya
- test yang ada hanya memverifikasi jalur sukses, padahal perubahan menambah jalur gagal atau edge case
- test yang ada terlalu umum dan tidak benar-benar mengunci perilaku yang baru diubah

### D. Ada komentar review yang menyatakan coverage kurang

Tambahkan test jika review menyatakan:

- perilaku penting belum dites
- edge case belum diuji
- regresi mudah terjadi tanpa test tambahan

### E. Perubahan menyentuh area berisiko

Test tambahan hampir selalu dibutuhkan bila perubahan menyentuh:

- autentikasi atau otorisasi
- pembayaran atau transaksi
- upload file
- parsing atau transformasi data penting
- query kompleks
- migrasi atau perubahan model data
- pipeline AI / proses batch / background jobs
- integrasi eksternal

## Kapan Test Tambahan Tidak Harus Dibuat

Test tambahan biasanya tidak wajib jika:

- perubahan hanya rename internal tanpa mengubah perilaku
- perubahan hanya komentar atau dokumentasi
- perubahan murni refactor kecil dan test yang ada sudah cukup melindungi perilaku
- perubahan sangat lokal dan sudah tercakup jelas oleh test yang ada

Tetap gunakan penilaian yang masuk akal. Jika ragu, tambahkan test.

## Urutan Verifikasi yang Disarankan

### Untuk perubahan kecil

1. Jalankan test yang paling relevan.
2. Jika tidak ada test yang relevan, tambahkan test yang paling dekat dengan perilaku yang diubah.
3. Jalankan ulang test.
4. Ringkas hasil.

### Untuk perubahan menengah atau lintas modul

1. Jalankan test yang paling relevan untuk area yang berubah.
2. Tambahkan test baru jika ada perilaku penting yang belum terlindungi.
3. Jalankan ulang test terkait.
4. Jika risikonya cukup besar, pertimbangkan menjalankan suite yang lebih luas.

### Untuk bug fix

1. Identifikasi skenario bug.
2. Tambahkan atau perbarui test yang mereproduksi bug.
3. Pastikan test gagal sebelum perbaikan jika memungkinkan.
4. Terapkan perbaikan.
5. Jalankan ulang test sampai lolos.
6. Ringkas hasil dan risiko.

## Ekspektasi Output Verifikasi

Saat melaporkan hasil verifikasi, minimal sertakan:

- area yang diubah
- test Laravel yang dijalankan
- test Python yang dijalankan
- test baru yang ditambahkan
- hasil lolos atau gagal
- risiko atau tindak lanjut jika masih ada masalah

### Contoh ringkasan

- Mengubah validasi upload dokumen di Laravel.
- Menjalankan `php artisan test --filter UploadDocumentTest`.
- Menambahkan 1 test untuk file kosong dan 1 test untuk tipe file tidak valid.
- Menjalankan `pytest -k "document"` pada modul Python yang terkait.
- Semua test lolos.
- Tidak ada blocker lanjutan.

## Aturan Akhir

- Jangan menyatakan tugas selesai sebelum test yang relevan dijalankan.
- Jika perilaku penting berubah dan test belum ada, tambahkan test.
- Jika ada gap coverage yang jelas, isi gap tersebut.
- Jika hasil verifikasi tidak meyakinkan, eskalasi untuk review lebih lanjut.

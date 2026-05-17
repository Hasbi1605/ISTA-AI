# Perbaikan Integritas DOCX Memo dan Baseline Revisi Terstruktur

## Latar Belakang

Setelah force-save OnlyOffice diterapkan, edit manual di editor sudah dapat disinkronkan sebelum download atau revisi via prompt. Namun uji manual berikutnya menunjukkan hasil revisi via prompt masih dapat memasukkan metadata lama seperti `Yth.`, `Dari`, `Hal`, dan `Tanggal` ke badan memo. Ini terjadi karena teks hasil ekstraksi DOCX dipakai sebagai baseline revisi tanpa pemisahan struktur memo yang cukup kuat.

Audit bug tambahan juga menemukan dua risiko valid:

1. Memo dapat menyimpan byte yang bukan DOCX valid sebagai file memo.
2. Generate memo pertama belum atomic dan dapat meninggalkan memo setengah jadi jika storage atau DB gagal di tengah proses.

## Validasi Temuan

### Valid - DOCX Korup Dapat Tersimpan

`MemoGenerationService::requestDraft()` menerima body sukses dari Python dan `storeDraft()` langsung menyimpan body tersebut sebagai `.docx` tanpa validasi struktur DOCX. Callback OnlyOffice sudah punya validasi awal, tetapi baru memeriksa ukuran dan prefix `PK`, sehingga byte korup yang menyerupai ZIP masih bisa lolos.

Test saat ini juga memakai payload seperti `docx-bytes` dan `PK...` yang bukan DOCX valid, sehingga test belum menjaga integritas file memo secara realistis.

### Valid - Generate Pertama Belum Atomic

`MemoGenerationService::generate()` membuat row `memos`, menyimpan file, membuat `memo_versions`, lalu mengaktifkan versi tanpa transaction dan tanpa cleanup bila salah satu langkah gagal. `generateRevision()` dan `generateRevisionFromBody()` sudah lebih aman karena memakai transaction dan menghapus file saat gagal.

`Storage::disk('local')->put()` juga tidak dicek hasilnya. Jika return `false` atau gagal diam-diam, DB tetap bisa mencatat path yang tidak punya file valid.

### Valid - Baseline Revisi dari Edit Manual Masih Terlalu Mentah

`DocxTextExtractor` mengekstrak teks seluruh dokumen, termasuk tabel metadata, QR/TTE, penandatangan, dan tembusan. `MemoWorkspace::memoBodyForRevision()` mencoba membuang struktur resmi, tetapi belum cukup kuat untuk hasil ekstraksi tabel yang memecah label dan nilai ke baris terpisah. Dampaknya, metadata lama dapat masuk ke badan memo saat revisi via prompt.

## Tujuan

- Pastikan semua file memo yang disimpan benar-benar DOCX valid.
- Pastikan generate memo pertama atomic dan tidak meninggalkan row/file setengah jadi.
- Pastikan revisi via prompt memakai isi memo bersih sebagai baseline, bukan teks dokumen penuh.
- Pastikan edit metadata seperti `Yth.` dilakukan deterministik ke konfigurasi memo dan tidak menduplikasi metadata lama ke badan dokumen.

## Ruang Lingkup

- Tambah validator DOCX reusable di Laravel.
- Terapkan validasi DOCX pada hasil Python memo generation dan callback OnlyOffice sebelum file dipersist.
- Ubah penyimpanan callback OnlyOffice menjadi temp-file-first, validate, lalu replace file lama.
- Bungkus generate memo pertama dalam transaction dan cleanup file bila gagal.
- Tambah parser/baseline extractor memo yang memisahkan struktur memo dari badan memo.
- Perbarui test agar memakai DOCX valid atau helper fixture DOCX valid.

## Di Luar Scope

- Perubahan besar layout template memo Python yang tidak terkait validasi dan baseline.
- Migrasi data massal untuk memo lama, kecuali nanti ditemukan file korup di production.
- Mengubah UX OnlyOffice secara besar selain status error yang diperlukan.

## Area / File Terkait

- `laravel/app/Services/Memo/MemoGenerationService.php`
- `laravel/app/Http/Controllers/OnlyOfficeCallbackController.php`
- `laravel/app/Services/OnlyOffice/DocxTextExtractor.php`
- `laravel/app/Livewire/Memos/MemoWorkspace.php`
- `laravel/tests/Feature/Memos/*`
- `python-ai/app/services/memo_generation.py`
- `python-ai/tests/test_memo_generation.py`

## Rekomendasi Final

1. Buat `DocxValidator` di Laravel yang memvalidasi byte/path DOCX dengan `ZipArchive`.
   - Wajib ada `[Content_Types].xml`.
   - Wajib ada `word/document.xml`.
   - Wajib bisa dibuka sebagai ZIP.
   - Opsional: validasi `word/_rels/document.xml.rels` bila diperlukan.

2. Terapkan validator pada semua jalur simpan memo.
   - `MemoGenerationService::requestDraft()` atau sebelum `storeDraft()` harus menolak body non-DOCX walaupun response HTTP 200.
   - `OnlyOfficeCallbackController` harus mengunduh ke path temporary, validasi, ekstrak teks, baru replace file lama.
   - Jangan overwrite file lama jika validasi file baru gagal.

3. Buat operasi generate awal atomic.
   - External HTTP request ke Python tetap dilakukan sebelum transaction.
   - Setelah draft valid, bungkus `Memo::create`, `storeDraft`, `createVersion`, dan `activateVersion` dalam `DB::transaction`.
   - Track path yang sudah ditulis dan hapus pada catch.
   - `storeDraft()` harus melempar exception jika `Storage::put()` tidak return `true`.

4. Buat extractor baseline memo yang struktur-aware.
   - Pisahkan hasil DOCX menjadi `metadata`, `body`, `closing`, `signatory`, `carbon_copy`, dan artefak QR/TTE.
   - Untuk revisi prompt, gunakan `body` bersih sebagai `body_override`.
   - Untuk instruksi metadata seperti `Ubah Yth menjadi ...`, update konfigurasi deterministik dan pertahankan body bersih.

5. Tambahkan defensive cleanup di Python generator.
   - Jika `body_override` masih mengandung label metadata (`Yth.`, `Dari`, `Hal`, `Tanggal`) atau artefak `QR/TTE`, buang sebelum render.
   - Ini menjadi lapisan pengaman kedua bila parser Laravel belum sempurna.

6. Perbarui test.
   - Ganti fake `docx-bytes` dan `PK...` untuk jalur sukses dengan fixture DOCX valid atau helper pembuat DOCX valid.
   - Tambah test bahwa corrupt `PK...` ditolak dan file lama tetap aman.
   - Tambah test initial generate rollback saat storage gagal.
   - Tambah test reproduksi manual edit lalu prompt `Ubah Yth ...`: hasil revisi tidak boleh memasukkan metadata lama ke body dan harus mempertahankan isi manual.

## Risiko

- Validasi DOCX yang terlalu ketat bisa menolak DOCX valid dari OnlyOffice jika hanya mengecek struktur minimum yang salah. Mitigasi: mulai dari check minimum `[Content_Types].xml` dan `word/document.xml`, lalu uji dengan fixture hasil Python dan OnlyOffice.
- Transaction DB tidak otomatis meng-rollback storage. Mitigasi: temp path dan cleanup eksplisit.
- Parser struktur memo bisa salah membuang isi yang kebetulan mirip metadata. Mitigasi: test dengan variasi tabel metadata, body naratif, tembusan, tanda tangan, dan edit manual bebas.
- Mengganti test fixture ke DOCX valid bisa membuat banyak test perlu disesuaikan. Mitigasi: buat helper `validDocxBytes()` agar perubahan test tidak melebar.

## Langkah Implementasi

1. Tambah `App\Services\OnlyOffice\DocxValidator` dan test unit untuk valid/corrupt DOCX.
2. Ubah `MemoGenerationService::storeDraft()` agar validasi DOCX dan cek `Storage::put()`.
3. Ubah `generate()` agar mengikuti pola transaction dan cleanup seperti revision.
4. Ubah `OnlyOfficeCallbackController` agar menyimpan response ke temp path, validate, extract, lalu replace.
5. Tambah `MemoDocumentStructureExtractor` atau perluas `DocxTextExtractor` untuk menghasilkan body bersih.
6. Ubah `MemoWorkspace::currentMemoBodyForRevision()` agar memakai body bersih dari struktur extractor.
7. Tambahkan cleanup defensive di `python-ai/app/services/memo_generation.py`.
8. Ganti test payload sukses menjadi DOCX valid dan tambah test regresi baru.

## Rencana Test

- Laravel targeted:
  - `php artisan test --filter=MemoGenerationService`
  - `php artisan test --filter=OnlyOfficeCallbackTest`
  - `php artisan test --filter=MemoWorkspaceTest`
  - `php artisan test --filter=MemoPolicyTest`
- Laravel full:
  - `php artisan test`
- Python targeted:
  - `cd python-ai && source venv/bin/activate && pytest tests/test_memo_generation.py`
- Build:
  - `cd laravel && npm run build`

## Kriteria Selesai

- Corrupt DOCX tidak bisa tersimpan dari generate Python maupun callback OnlyOffice.
- File lama tidak tertimpa jika callback menerima file baru yang invalid.
- Initial generate tidak menyisakan memo/version/file setengah jadi saat storage atau DB gagal.
- Revisi prompt setelah edit manual tidak menduplikasi metadata lama ke badan memo.
- Semua test targeted dan full Laravel lulus.
- Test Python memo generation lulus.

## Hasil Implementasi

- Menambahkan validator DOCX berbasis `ZipArchive` untuk memastikan file memiliki `[Content_Types].xml` dan `word/document.xml`.
- Menerapkan validasi DOCX pada hasil generate Laravel dan callback OnlyOffice sebelum file memo diganti.
- Membuat initial generate memo atomic dengan transaction dan cleanup storage eksplisit bila operasi DB/storage gagal.
- Memindahkan ekstraksi body revisi ke parser struktur memo agar metadata/table/header/footer tidak masuk ke baseline prompt.
- Menambahkan sanitasi defensif di Python generator untuk membuang artefak struktur memo dari `body_override`.
- Memperketat parser agar kalimat isi seperti `Hal ini...`, `Dari hasil...`, dan `Tanggal pelaksanaan...` tidak ikut terhapus.

## Hasil Verifikasi

- `cd laravel && ./vendor/bin/pint --dirty`
- `cd laravel && php artisan test --filter=DocxValidatorTest`
- `cd laravel && php artisan test --filter=MemoDocumentStructureExtractorTest`
- `cd laravel && php artisan test --filter=Memos`
- `cd laravel && php artisan test`
- `cd laravel && npm run build`
- `cd python-ai && source venv/bin/activate && pytest tests/test_memo_generation.py`
- `cd python-ai && source venv/bin/activate && pytest`

# Issue 201 Runtime Chat/Document Bugfix

## Latar Belakang
GitHub issue #201 mencatat bug runtime dan data integrity pada chat streaming, cleanup cloud storage, preview job dokumen, dan ordering pesan. Perbaikan harus kecil, terarah, dan tetap mempertahankan fallback job chat yang sudah ada.

## Tujuan
- Mencegah duplicate AI runner pada race stream claim.
- Menghapus `cloud_storage_files` terkait saat document atau conversation dihapus.
- Membuat preview job aman ketika document sudah hard-deleted sebelum job diproses.
- Membuat urutan pesan stabil saat `created_at` sama.

## Ruang Lingkup
- Laravel chat orchestration dan stream controller.
- Delete document dan delete conversation cleanup.
- Render document preview job guard.
- Targeted feature/unit tests untuk perilaku di atas.

## Di Luar Scope
- Technical debt issue #202: dual OTP path, MemoCanvas legacy, ProcessDocument fallback, SOURCES parser hardening, memo version token binding.
- Perubahan Python AI.
- Refactor besar struktur chat/document.

## Area / File Terkait
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- `laravel/app/Livewire/Chat/ChatIndex.php`
- `laravel/app/Services/DocumentLifecycleService.php`
- `laravel/app/Jobs/RenderDocumentPreview.php`
- `laravel/tests/Feature/Chat/ChatStreamTest.php`
- `laravel/tests/Feature/Documents/DocumentDeletionTest.php`
- `laravel/tests/Feature/Jobs/ProcessDocumentTest.php`

## Risiko
- Stream claim harus tetap membiarkan job fallback recover jika stream tidak pernah connect atau gagal.
- Delete conversation harus menghapus cloud rows sebelum message rows hilang.
- Tests queue/model serialization perlu memverifikasi behavior tanpa membuat test brittle terhadap internal Laravel queue.

## Langkah Implementasi
1. Pisahkan pembuatan intent dan acquisition runner stream.
2. Gunakan cache lock saat transisi claim ke `active`.
3. Update caller: `sendMessage()` membuat intent, `ChatStreamController` acquire runner.
4. Tambahkan cleanup `cloud_storage_files` untuk document dan conversation delete.
5. Tambahkan `deleteWhenMissingModels` dan guard `failed()` pada `RenderDocumentPreview`.
6. Tambahkan tie-break `orderBy('id')` untuk load pesan.
7. Tambahkan atau update tests relevan.

## Rencana Test
- Jalankan targeted tests chat stream claim.
- Jalankan targeted tests document deletion/cloud storage cleanup.
- Jalankan targeted tests process/preview job.
- Jalankan targeted test untuk ordering pesan.
- Jika targeted tests stabil, jalankan suite Laravel relevan atau full `php artisan test`.

## Kriteria Selesai
- Duplicate stream tidak memanggil AI dua kali.
- Job fallback tetap defer saat claim aktif.
- Delete document/conversation tidak meninggalkan cloud rows.
- Preview job aman untuk missing document.
- Message ordering deterministic.
- Tests relevan lulus.

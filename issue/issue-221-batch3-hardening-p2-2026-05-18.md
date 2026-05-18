# Batch 3 Hardening P2

## Latar Belakang
Audit lanjutan menemukan beberapa hardening P2 yang murah tetapi berguna: query string `document_ids` bisa dibuat sangat besar, resolver stale chat dapat menulis error saat stream masih aktif, job pemrosesan dokumen membaca seluruh file ke memori sebelum upload ke service Python, dan shortcut Google Drive belum punya status/error yang jelas.

## Tujuan
- Batasi dan deduplikasi ID dokumen yang dipakai untuk konteks chat.
- Cegah stale resolver menulis error saat stream claim masih aktif.
- Upload file dokumen ke service Python memakai stream file, bukan string penuh di memori.
- Buat shortcut Google Drive tampil dan gagal dengan pesan yang eksplisit.

## Ruang Lingkup
- Laravel chat stream/orchestration untuk normalisasi `document_ids`.
- Command `chat:resolve-stale-responses`.
- Job `ProcessDocument`.
- Google Drive picker/service metadata dan pesan unsupported shortcut.
- Test targeted untuk perilaku yang berubah.

## Di Luar Scope
- Resolve shortcut Google Drive ke target binary.
- Refactor besar pipeline chat, queue, atau Google Drive.
- Deploy production, kecuali diminta setelah implementasi.

## Area / File Terkait
- `laravel/app/Services/ChatOrchestrationService.php`
- `laravel/app/Http/Controllers/Chat/ChatStreamController.php`
- `laravel/app/Console/Commands/ResolveStaleChats.php`
- `laravel/app/Jobs/ProcessDocument.php`
- `laravel/app/Services/CloudStorage/GoogleDriveService.php`
- `laravel/resources/views/livewire/chat/google-drive-picker.blade.php`
- Test chat, console, job, dan Google Drive service.

## Risiko
- Membatasi dokumen ke 50 ID dapat memotong pilihan lama yang lebih besar dari batas itu, tetapi ini sesuai kebutuhan hardening dan mencegah query `whereIn` besar.
- Stream resource harus ditutup di semua path exception.
- Shortcut Google Drive sengaja tidak di-resolve untuk menghindari akses target di luar root folder kantor.

## Langkah Implementasi
1. Tambahkan batas maksimum 50 ID di normalisasi dokumen chat dan gunakan normalizer tersebut di stream parser/latest message.
2. Tambahkan skip di stale resolver saat `hasActiveStreamClaim()` true.
3. Ganti `file_get_contents()` di `ProcessDocument` dengan `fopen(..., 'rb')` dan tutup resource di `finally`.
4. Tandai Google Drive shortcut dengan metadata `is_shortcut`, `unsupported_reason`, dan error eksplisit pada `downloadToTemp()`.
5. Tambahkan test targeted.

## Rencana Test
- `php artisan test --filter='ChatStreamTest|ChatUiTest|ResolveStaleChatsTest|ProcessDocumentTest|GoogleDriveServiceTest|GoogleDrivePickerTest'`
- `./vendor/bin/pint --test` untuk file terdampak.
- `npm run build` karena view/frontend picker ikut berubah.

## Kriteria Selesai
- Semua fix Batch 3 terimplementasi dengan patch kecil.
- Test targeted lulus.
- Formatting/lint Laravel lulus.
- Build frontend lulus.
